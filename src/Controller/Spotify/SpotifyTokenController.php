<?php
namespace ControleOnline\Controller\Spotify;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SpotifyTokenController extends AbstractController
{
    public function __construct(private HttpClientInterface $httpClient) {}

    #[Route('/spotify/token', name: 'spotify_token', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $clientId = $_ENV['SPOTIFY_CLIENT_ID'] ?? '';
        $clientSecret = $_ENV['SPOTIFY_CLIENT_SECRET'] ?? '';

        if (!$clientId || !$clientSecret) {
            return $this->json(['error' => 'Spotify credentials not set'], 500);
        }

        try {
            $response = $this->httpClient->request('POST', 'https://accounts.spotify.com/api/token', [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode("$clientId:$clientSecret"),
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => [
                    'grant_type' => 'client_credentials',
                ],
            ]);

            $data = $response->toArray();

            return $this->json([
                'access_token' => $data['access_token'] ?? null,
                'expires_in' => $data['expires_in'] ?? null,
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }
}