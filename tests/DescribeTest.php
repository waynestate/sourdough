<?php

declare(strict_types=1);

namespace Waynestate\Sourdough\Tests;

use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Responses\Meta\MetaInformation;
use OpenAI\Resources\Chat;
use OpenAI\Testing\ClientFake;
use PHPUnit\Framework\TestCase;
use Waynestate\Sourdough\Describe;
use Waynestate\Sourdough\DescribeException;
use Waynestate\Sourdough\Part;
use Waynestate\Sourdough\Permutation;

final class DescribeTest extends TestCase
{
    private const ENV_VARS = [
        'SOURDOUGH_LLM_BASE_URL',
        'SOURDOUGH_LLM_API_KEY',
        'SOURDOUGH_LLM_MODEL',
    ];

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        foreach (self::ENV_VARS as $name) {
            $this->savedEnv[$name] = getenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }
        $this->savedEnv = [];

        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->tempFiles = [];
    }

    public function testDescribeReturnsAppPlanFromValidAnswer(): void
    {
        $this->setEnv();
        $fake = new ClientFake([$this->chatResponse($this->validPlanJson())]);
        $describe = new Describe($fake);

        $plan = $describe->describe('A blog with posts and comments.');

        self::assertSame(Permutation::Raw, $plan->permutation);
        self::assertSame([Part::Sesame, Part::Helmet], $plan->parts);
        self::assertSame('demo-app', $plan->name);
        self::assertCount(1, $plan->models);
        self::assertSame('Post', $plan->models[0]['name']);
        self::assertSame(['/', '/posts'], $plan->pages);
        $fake->assertSent(Chat::class, 1);
    }

    public function testDescribeRetriesWhenTheFirstAnswerIsNotJson(): void
    {
        $this->setEnv();
        $fake = new ClientFake([
            $this->chatResponse('Here is the plan you asked for:'),
            $this->chatResponse($this->validPlanJson()),
        ]);
        $describe = new Describe($fake);

        $plan = $describe->describe('A blog with posts and comments.');

        self::assertSame('demo-app', $plan->name);
        $fake->assertSent(Chat::class, 2);
    }

    public function testDescribeRetriesWhenTheFirstAnswerFailsTheSchema(): void
    {
        $this->setEnv();
        $bad = json_encode(['permutation' => 'raw', 'name' => 'demo-app']);
        $fake = new ClientFake([
            $this->chatResponse((string) $bad),
            $this->chatResponse($this->validPlanJson()),
        ]);
        $describe = new Describe($fake);

        $plan = $describe->describe('A blog with posts and comments.');

        self::assertSame('demo-app', $plan->name);
        $fake->assertSent(Chat::class, 2);
    }

    public function testDescribeGivesUpAfterTwoBadAnswers(): void
    {
        $this->setEnv();
        $fake = new ClientFake([
            $this->chatResponse('not json at all'),
            $this->chatResponse('still not json'),
        ]);
        $describe = new Describe($fake);

        $this->expectException(DescribeException::class);
        $this->expectExceptionMessage(Describe::FALLBACK_MESSAGE);

        $describe->describe('A blog with posts and comments.');
    }

    public function testDescribeMissingEnvThrowsWithoutCallingTheModel(): void
    {
        foreach (self::ENV_VARS as $name) {
            putenv($name);
        }
        $fake = new ClientFake();
        $describe = new Describe($fake);

        $this->expectException(DescribeException::class);
        $this->expectExceptionMessage('SOURDOUGH_LLM_BASE_URL');

        $describe->describe('A blog with posts and comments.');
    }

    public function testFromFileReturnsAppPlan(): void
    {
        $path = $this->writeTempFile($this->validPlanJson());

        $plan = (new Describe())->fromFile($path);

        self::assertSame(Permutation::Raw, $plan->permutation);
        self::assertSame('demo-app', $plan->name);
    }

    public function testFromFileMissingFileThrows(): void
    {
        $this->expectException(DescribeException::class);
        $this->expectExceptionMessage('does not exist');

        (new Describe())->fromFile(__DIR__ . '/does-not-exist.json');
    }

    public function testFromFileInvalidPlanThrows(): void
    {
        $path = $this->writeTempFile((string) json_encode(['permutation' => 'raw', 'name' => 'demo-app']));

        $this->expectException(DescribeException::class);
        $this->expectExceptionMessage('not a valid plan');

        (new Describe())->fromFile($path);
    }

    private function setEnv(): void
    {
        putenv('SOURDOUGH_LLM_BASE_URL=https://example.test/v1');
        putenv('SOURDOUGH_LLM_API_KEY=test-key');
        putenv('SOURDOUGH_LLM_MODEL=test-model');
    }

    private function chatResponse(string $content): CreateResponse
    {
        return CreateResponse::from([
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion',
            'created' => 1_677_652_288,
            'model' => 'test-model',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => $content,
                    ],
                    'logprobs' => null,
                    'finish_reason' => 'stop',
                ],
            ],
        ], MetaInformation::from([]));
    }

    private function validPlanJson(): string
    {
        return (string) json_encode([
            'permutation' => 'raw',
            'parts' => ['sesame', 'helmet'],
            'name' => 'demo-app',
            'models' => [
                [
                    'name' => 'Post',
                    'fields' => [
                        ['name' => 'title', 'type' => 'string'],
                        ['name' => 'body', 'type' => 'text'],
                    ],
                ],
            ],
            'pages' => ['/', '/posts'],
        ], JSON_UNESCAPED_SLASHES);
    }

    private function writeTempFile(string $content): string
    {
        $path = sys_get_temp_dir() . '/sourdough-plan-' . bin2hex(random_bytes(8)) . '.json';
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }
}
