<?php

namespace ControleOnline\Command;

use ControleOnline\Entity\ExtraData;
use ControleOnline\Entity\ExtraFields;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\People;
use ControleOnline\Repository\ExtraDataRepository;
use ControleOnline\Repository\ExtraFieldsRepository;
use ControleOnline\Service\DatabaseSwitchService;
use ControleOnline\Service\LoggerService;
use ControleOnline\Service\SkyNetService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\LockFactory;

#[AsCommand(
    name: 'integration:marketplace:cleanup-extra-data',
    description: 'Materializa snapshots do marketplace em Order/People e remove legado rico de extra_data/extra_fields.',
)]
class CleanupMarketplaceExtraDataCommand extends DefaultCommand
{
    private const ENTITY_BATCH_SIZE = 20;
    private const PROGRESS_EVERY = 25;
    private const TARGET_CONTEXTS = [Order::APP_FOOD99, Order::APP_IFOOD];
    private const TARGET_ENTITIES = ['Order', 'People'];

    public function __construct(
        LockFactory $lockFactory,
        DatabaseSwitchService $databaseSwitchService,
        LoggerService $loggerService,
        SkyNetService $skyNetService,
        private EntityManagerInterface $entityManager,
        private ExtraDataRepository $extraDataRepository,
        private ExtraFieldsRepository $extraFieldsRepository,
    ) {
        $this->lockFactory = $lockFactory;
        $this->databaseSwitchService = $databaseSwitchService;
        $this->loggerService = $loggerService;
        $this->skyNetService = $skyNetService;

        parent::__construct('integration:marketplace:cleanup-extra-data');
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nao persiste mudancas.')
            ->addOption('contexts', null, InputOption::VALUE_OPTIONAL, 'Contexts marketplace alvo', implode(',', self::TARGET_CONTEXTS))
            ->addOption('entity-names', null, InputOption::VALUE_OPTIONAL, 'Entidades alvo', implode(',', self::TARGET_ENTITIES));
    }

    protected function runCommand(): int
    {
        $dryRun = (bool) $this->input->getOption('dry-run');
        $contexts = $this->normalizeCsvOption((string) $this->input->getOption('contexts'), self::TARGET_CONTEXTS);
        $entityNames = $this->normalizeCsvOption((string) $this->input->getOption('entity-names'), self::TARGET_ENTITIES);

        if ($contexts === [] || $entityNames === []) {
            $this->addLog('<comment>Sem contexts ou entidades alvo para limpeza.</comment>');
            return Command::SUCCESS;
        }

        $legacyRows = $this->extraDataRepository->iterateMarketplaceLegacyRows($contexts, $entityNames);
        if (!is_iterable($legacyRows)) {
            $this->addLog('<comment>Nenhum extra_data legado encontrado para os contexts informados.</comment>');
            return Command::SUCCESS;
        }

        $materialized = 0;
        $deleted = 0;
        $keptIdentifiers = 0;
        $skipped = 0;
        $deletedFieldNames = [];
        $processedEntities = 0;
        $currentGroup = null;

        foreach ($legacyRows as $legacyRow) {
            if (!$legacyRow instanceof ExtraData) {
                continue;
            }

            $extraFields = $legacyRow->getExtraFields();
            if (!$extraFields instanceof ExtraFields) {
                $skipped++;
                continue;
            }

            $entityName = strtolower(trim($legacyRow->getEntityName()));
            $entityClass = match ($entityName) {
                'order' => Order::class,
                'people' => People::class,
                default => null,
            };

            if ($entityClass === null) {
                $skipped++;
                continue;
            }

            $entityId = (int) $legacyRow->getEntityId();
            if ($entityId <= 0) {
                $skipped++;
                continue;
            }

            $entityKey = $entityClass . ':' . $entityId;
            if (is_array($currentGroup) && $currentGroup['entity_key'] !== $entityKey) {
                $this->processMarketplaceLegacyEntityGroup(
                    $currentGroup,
                    $dryRun,
                    $materialized,
                    $deleted,
                    $keptIdentifiers,
                    $deletedFieldNames
                );

                $processedEntities++;
                if (!$dryRun && ($processedEntities % self::ENTITY_BATCH_SIZE) === 0) {
                    $this->entityManager->flush();
                }

                if (!$dryRun && ($processedEntities % self::PROGRESS_EVERY) === 0) {
                    $this->addLog(sprintf(
                        '[integration:marketplace:cleanup-extra-data] progresso=%d materialized=%d deleted_extra_data=%d kept_ids_codes=%d skipped=%d',
                        $processedEntities,
                        $materialized,
                        $deleted,
                        $keptIdentifiers,
                        $skipped
                    ));
                }

                $currentGroup = null;
            }

            if (!is_array($currentGroup)) {
                $entity = $this->entityManager->getRepository($entityClass)->find($entityId);
                if (!$entity instanceof Order && !$entity instanceof People) {
                    $skipped++;
                    continue;
                }

                $currentGroup = [
                    'entity_key' => $entityKey,
                    'entity' => $entity,
                    'rows' => [],
                ];
            }

            $currentGroup['rows'][] = [
                'legacy_row' => $legacyRow,
                'field_name' => trim((string) $extraFields->getName()),
                'context' => trim((string) $extraFields->getContext()),
                'raw_value' => $this->normalizeLegacyValue($legacyRow->getValue()),
            ];
        }

        if (is_array($currentGroup)) {
            $this->processMarketplaceLegacyEntityGroup(
                $currentGroup,
                $dryRun,
                $materialized,
                $deleted,
                $keptIdentifiers,
                $deletedFieldNames
            );

            $processedEntities++;
        }

        $deletedExtraFields = 0;
        if (!$dryRun) {
            $this->entityManager->flush();

            $fieldNamesToReap = [];
            foreach ($deletedFieldNames as $context => $fieldNames) {
                foreach (array_keys($fieldNames) as $fieldName) {
                    $fieldNamesToReap[] = $fieldName;
                }
            }

            $fieldNamesToReap = array_values(array_unique(array_filter($fieldNamesToReap)));
            if ($fieldNamesToReap !== []) {
                $orphanFields = $this->extraFieldsRepository->findUnusedMarketplaceFields($contexts, $fieldNamesToReap);
                foreach ($orphanFields as $orphanField) {
                    if ($orphanField instanceof ExtraFields) {
                        $this->entityManager->remove($orphanField);
                        $deletedExtraFields++;
                    }
                }

                if ($deletedExtraFields > 0) {
                    $this->entityManager->flush();
                }
            }
        }

        $this->addLog(sprintf(
            '[integration:marketplace:cleanup-extra-data] dry_run=%s materialized=%d deleted_extra_data=%d kept_ids_codes=%d deleted_extra_fields=%d skipped=%d',
            $dryRun ? '1' : '0',
            $materialized,
            $deleted,
            $keptIdentifiers,
            $deletedExtraFields,
            $skipped
        ));

        return Command::SUCCESS;
    }

    /**
     * @param array{
     *     entity_key: string,
     *     entity: object,
     *     rows: array<int, array{legacy_row: ExtraData, field_name: string, context: string, raw_value: mixed}>
     * } $group
     */
    private function processMarketplaceLegacyEntityGroup(
        array $group,
        bool $dryRun,
        int &$materialized,
        int &$deleted,
        int &$keptIdentifiers,
        array &$deletedFieldNames
    ): void {
        $entity = $group['entity'];
        $otherInformations = $this->decodeOtherInformations($entity->getOtherInformations(true));
        $entityUpdated = false;
        $legacyRowIdsToDelete = [];

        foreach ($group['rows'] as $row) {
            $fieldName = $row['field_name'];
            $context = $row['context'];
            $legacyRow = $row['legacy_row'];
            $rawValue = $row['raw_value'];
            $hasMeaningfulRawValue = $this->isMeaningfulLegacyValue($rawValue);

            $block = $this->decodeOtherInformations($otherInformations[$context] ?? null);
            if (
                $hasMeaningfulRawValue
                && (!array_key_exists($fieldName, $block) || $block[$fieldName] === null || $block[$fieldName] === '')
            ) {
                $block[$fieldName] = $rawValue;
                $otherInformations[$context] = $block;
                $materialized++;
                $entityUpdated = true;
            }

            if ($this->isAllowedLegacyField($fieldName) && $hasMeaningfulRawValue) {
                $keptIdentifiers++;
                continue;
            }

            $deletedFieldNames[$context][$fieldName] = true;

            if (!$dryRun) {
                $legacyRowIdsToDelete[] = (int) $legacyRow->getId();
                $deleted++;
            }
        }

        if ($entityUpdated) {
            $entity->setOtherInformations($otherInformations);
            if (!$dryRun) {
                $this->entityManager->persist($entity);
            }
        }

        if (!$dryRun && $legacyRowIdsToDelete !== []) {
            foreach (array_chunk(array_values(array_unique($legacyRowIdsToDelete)), 500) as $legacyRowIdChunk) {
                $this->extraDataRepository->deleteByIds($legacyRowIdChunk);
            }

            $this->entityManager->clear(ExtraData::class);
            $this->entityManager->clear(ExtraFields::class);
        }
    }

    private function normalizeCsvOption(string $value, array $default): array
    {
        $items = array_values(array_filter(array_map(
            static fn (string $item): string => trim($item),
            explode(',', $value)
        )));

        if ($items === []) {
            return $default;
        }

        return array_values(array_unique($items));
    }

    private function normalizeLegacyValue(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_string($value)) {
            $normalized = trim($value);

            return $normalized !== '' ? $normalized : null;
        }

        if (is_array($value) || is_object($value)) {
            $normalized = json_decode(json_encode($value), true);

            return is_array($normalized) && $normalized !== [] ? $normalized : null;
        }

        return $value;
    }

    private function isMeaningfulLegacyValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }

    private function materializeOtherInformations(object $entity, string $context, string $fieldName, mixed $value): bool
    {
        if (!method_exists($entity, 'getOtherInformations') || !method_exists($entity, 'setOtherInformations')) {
            return false;
        }

        $otherInformations = $this->decodeOtherInformations($entity->getOtherInformations(true));
        $block = $this->decodeOtherInformations($otherInformations[$context] ?? null);

        if (array_key_exists($fieldName, $block) && $block[$fieldName] !== null && $block[$fieldName] !== '') {
            return false;
        }

        $block[$fieldName] = $value;
        $otherInformations[$context] = $block;
        $entity->setOtherInformations($otherInformations);

        return true;
    }

    private function decodeOtherInformations(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            $normalized = json_decode(json_encode($value), true);

            return is_array($normalized) ? $normalized : [];
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        return [];
    }

    private function isAllowedLegacyField(string $fieldName): bool
    {
        $normalized = strtolower(trim($fieldName));
        if ($normalized === '') {
            return false;
        }

        if (in_array($normalized, ['id', 'code', 'merchant_id', 'shop_id'], true)) {
            return true;
        }

        return str_ends_with($normalized, '_id') || str_ends_with($normalized, '_code');
    }
}
