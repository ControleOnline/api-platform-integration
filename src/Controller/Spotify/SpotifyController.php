<?php

namespace ControleOnline\Controller\Spotify;

use ControleOnline\Entity\People;
use ControleOnline\Service\ConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Doctrine\ORM\EntityManagerInterface;
use Exception;

class SpotifyController extends AbstractController
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private EntityManagerInterface $manager,
        private ConfigService $configService
    ) {}

    #[Route('/spotify/token/{peopleId}', name: 'spotify_token_per_kitchen', methods: ['GET'])]
    public function token(string $peopleId): JsonResponse
    {
        try {

            $people = $this->manager->getRepository(People::class)->find($peopleId);
            if (!$people)
                throw new Exception('People not found');

            $spotify_autentication =  json_decode($this->configService->discoveryConfig($people, 'spotify_autentication')?->getConfigValue(), true);

            if (!$spotify_autentication)
                throw new Exception('Spotify is not configured');

            $clientId = $_ENV['SPOTIFY_CLIENT_ID'];
            $clientSecret = $_ENV['SPOTIFY_CLIENT_SECRET'];
            $refreshToken = $spotify_autentication['REFRESH_TOKEN'];


            $response = $this->httpClient->request('POST', 'https://accounts.spotify.com/api/token', [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode("$clientId:$clientSecret"),
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                ],
            ]);

            return $this->json($response->toArray());
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }
}
