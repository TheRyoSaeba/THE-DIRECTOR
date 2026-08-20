<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class ProxyService
{
    
    public static function resolveDirectUrl(string $url): string
    {
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }

        
        if (preg_match('/\.(jpg|jpeg|png|gif|webp|bmp|svg)$/i', parse_url($url, PHP_URL_PATH) ?? '')) {
            return $url;
        }

        try {
            
            $response = Http::withoutVerifying()
                ->timeout(5)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                ])
                ->get($url);

            if ($response->successful()) {
                $contentType = $response->header('Content-Type');
                
                
                if (str_starts_with($contentType, 'image/')) {
                    return $url;
                }

                
                if (str_contains($contentType, 'text/html')) {
                    if (preg_match('/<meta[^>]+property="og:image"[^>]+content="([^"]+)"/i', $response->body(), $matches)) {
                        return $matches[1];
                    }
                }
            }
        } catch (\Exception $e) {
            
        }

        return $url;
    }
}
