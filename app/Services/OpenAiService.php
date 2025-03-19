<?php

namespace App\Services;

use App\Services\Contracts\OpenAiServiceInterface;
use Exception;
use OpenAI\Laravel\Facades\OpenAI;

class OpenAiService implements OpenAiServiceInterface
{

    public function generate(
        string $prompt,
        string $instruction = '',
        string $model = 'text-davinci-003',
        int $maxTokens = 100
    ): string {
        try {
            $response = OpenAI::complete([
                'model' => $model,
                'prompt' => $prompt,
                'max_tokens' => $maxTokens,
            ]);

            return $response->choices[0]->text;
        } catch (Exception $e) {
            return $e->getMessage();
        }
    }
}
