<?php

declare(strict_types=1);

namespace Waynestate\Sourdough;

use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;
use OpenAI\Contracts\ClientContract;
use RuntimeException;

/**
 * Asks an OpenAI-compatible model to plan an app from a sentence, and reads
 * back a ready-made plan from a file.
 *
 * The model does the work but never gates: a bad or missing answer means
 * "write the plan yourself" (pass --plan=<file>), never a blocked command.
 *
 * The client is injectable so tests can pass a fake; by default it is built
 * from the SOURDOUGH_LLM_* environment variables.
 */
final class Describe
{
    public const FALLBACK_MESSAGE = 'The model could not produce a valid plan. Write the plan yourself and pass it with --plan=<file>.';

    private const ENV_BASE_URL = 'SOURDOUGH_LLM_BASE_URL';
    private const ENV_API_KEY = 'SOURDOUGH_LLM_API_KEY';
    private const ENV_MODEL = 'SOURDOUGH_LLM_MODEL';

    private const SCHEMA_PATH = __DIR__ . '/../resources/plan.schema.json';

    public function __construct(
        private readonly ?ClientContract $client = null,
    ) {}

    /**
     * Ask the model to plan an app from a sentence.
     *
     * @throws DescribeException when the environment is not set or the model gives up
     */
    public function describe(string $sentence): AppPlan
    {
        $config = $this->config();
        $client = $this->client ?? $this->makeClient($config['base_url'], $config['api_key']);

        $content = $this->chat($client, $config['model'], $this->prompt($sentence));
        [$data, $failure] = $this->parse($content);

        if (null !== $failure) {
            $content = $this->chat($client, $config['model'], $this->prompt($sentence, $failure));
            [$data, $failure] = $this->parse($content);

            if (null !== $failure) {
                throw new DescribeException(self::FALLBACK_MESSAGE);
            }
        }

        if (null === $data) {
            throw new DescribeException(self::FALLBACK_MESSAGE);
        }

        return AppPlan::fromArray($data);
    }

    /**
     * Read and validate a ready-made plan from a file.
     *
     * @throws DescribeException when the file is missing, not JSON, or fails the schema
     */
    public function fromFile(string $path): AppPlan
    {
        if (!is_file($path)) {
            throw new DescribeException(sprintf('The plan file "%s" does not exist.', $path));
        }

        $content = file_get_contents($path);
        if (false === $content) {
            throw new DescribeException(sprintf('Could not read the plan file "%s".', $path));
        }

        [$data, $failure] = $this->parse($content);
        if (null !== $failure) {
            throw new DescribeException(sprintf('The plan file "%s" is not a valid plan: %s.', $path, $failure));
        }

        if (null === $data) {
            throw new DescribeException(sprintf('The plan file "%s" is not a valid plan.', $path));
        }

        return AppPlan::fromArray($data);
    }

    /**
     * @return array{base_url: string, api_key: string, model: string}
     */
    private function config(): array
    {
        return [
            'base_url' => $this->env(self::ENV_BASE_URL),
            'api_key' => $this->env(self::ENV_API_KEY),
            'model' => $this->env(self::ENV_MODEL),
        ];
    }

    private function env(string $name): string
    {
        $value = getenv($name);
        if (false === $value || '' === $value) {
            throw new DescribeException(sprintf(
                'The %s environment variable is not set. Set it, or pass a ready-made plan with --plan=<file>.',
                $name,
            ));
        }

        return $value;
    }

    private function makeClient(string $baseUrl, string $apiKey): ClientContract
    {
        return \OpenAI::factory()
            ->withApiKey($apiKey)
            ->withBaseUri($baseUrl)
            ->make();
    }

    private function chat(ClientContract $client, string $model, string $prompt): string
    {
        $response = $client->chat()->create([
            'model' => $model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ]);

        return (string) $response->choices[0]->message->content;
    }

    private function prompt(string $sentence, ?string $failure = null): string
    {
        $prompt = "You are planning a Wayne State University application.\n"
            . "Given the description of what the app does, produce a plan as a single JSON object.\n"
            . "Respond with ONLY the JSON object - no prose, no markdown, no code fences.\n\n"
            . "The JSON object must match this schema:\n"
            . $this->schemaJson() . "\n\n"
            . "Description of the app:\n"
            . $sentence . "\n";

        if (null !== $failure) {
            $prompt .= "\nYour previous answer was not valid. Here is why:\n"
                . $failure . "\n\n"
                . "Try again. Respond with ONLY the JSON object.\n";
        }

        return $prompt;
    }

    /**
     * Decode and validate a model answer or plan file.
     *
     * @return array{0: array<string, mixed>|null, 1: string|null} the decoded plan and null, or null and the reason it failed
     */
    private function parse(string $content): array
    {
        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return [null, 'the answer was not valid JSON: ' . $e->getMessage()];
        }

        if (!is_array($data)) {
            return [null, 'the answer was JSON but not an object'];
        }

        $errors = $this->validate($data);
        if ($errors !== []) {
            return [null, 'the answer did not match the plan schema: ' . implode('; ', $errors)];
        }

        return [$data, null];
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function validate(array $data): array
    {
        $validator = new Validator();
        // CHECK_MODE_TYPE_CAST makes the validator treat associative arrays as
        // JSON objects (we decode with json_decode(..., true)).
        $validator->validate($data, $this->schema(), Constraint::CHECK_MODE_TYPE_CAST);

        $errors = [];
        foreach ($validator->getErrors() as $error) {
            $property = (string) ($error['property'] ?? '');
            $message = (string) ($error['message'] ?? '');
            $errors[] = '' !== $property ? $property . ': ' . $message : $message;
        }

        return $errors;
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        $content = file_get_contents(self::SCHEMA_PATH);
        if (false === $content) {
            throw new RuntimeException('Could not read the plan schema at ' . self::SCHEMA_PATH);
        }

        $schema = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return is_array($schema) ? $schema : [];
    }

    private function schemaJson(): string
    {
        return (string) json_encode($this->schema(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
