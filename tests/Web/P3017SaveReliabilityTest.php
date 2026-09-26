<?php

declare(strict_types=1);

namespace Goal\Legacy\Tests\Web;

use DateTimeImmutable;
use Goal\Legacy\Core\Bootstrap\Bootstrap;
use Goal\Legacy\Core\Persistence\SaveMetadata;
use Goal\Legacy\Core\Time\SimulationTime;
use Goal\Legacy\Web\WebApplication;
use PHPUnit\Framework\TestCase;

final class P3017SaveReliabilityTest extends TestCase
{
    private string $root;
    private WebApplication $application;
    /** @var list<string> */
    private array $saveIds = [];

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $services = (new Bootstrap())->create($this->root, ['APP_ENV' => 'test']);
        $this->application = new WebApplication($services, $this->root);
    }

    protected function tearDown(): void
    {
        foreach ($this->saveIds as $saveId) {
            $path = $this->root . '/game/saves/' . $saveId . '.sqlite';
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testSaveListSkipsUnreadableFilesWithoutDeletingThem(): void
    {
        $saveId = $this->saveId('list-corrupt');
        $path = $this->root . '/game/saves/' . $saveId . '.sqlite';
        file_put_contents($path, 'not a sqlite database');
        $this->saveIds[] = $saveId;

        $services = (new Bootstrap())->create($this->root, ['APP_ENV' => 'test']);
        $saves = $services->saveStore()->list();

        self::assertNotContains($saveId, array_map(static fn (SaveMetadata $metadata): string => $metadata->id(), $saves));
        self::assertFileExists($path);
    }

    public function testDirectOpenOfCorruptSaveUsesBoundedRecoveryCopy(): void
    {
        $saveId = $this->saveId('direct-corrupt');
        $path = $this->root . '/game/saves/' . $saveId . '.sqlite';
        file_put_contents($path, 'not a sqlite database');
        $this->saveIds[] = $saveId;

        $session = [];
        $response = $this->application->handle('GET', '/', ['page' => 'home', 'save' => $saveId], [], $session);

        self::assertSame(500, $response['status']);
        self::assertStringContainsString('could not be opened', strtolower($response['body']));
        self::assertStringContainsString('Return to Careers', $response['body']);
        self::assertStringNotContainsString($this->root, $response['body']);
        self::assertStringNotContainsString('PDOException', $response['body']);
        self::assertStringNotContainsString('core_save_metadata', $response['body']);
        self::assertFileExists($path);
    }

    public function testSaveListAndRoutesAreScopedToMetadataOwnership(): void
    {
        $saveId = $this->saveId('owned');
        $services = (new Bootstrap())->create($this->root, ['APP_ENV' => 'test']);
        $services->saveStore()->create(SaveMetadata::create(
            $saveId,
            'Owned Reliability Career',
            new SimulationTime(0),
            new DateTimeImmutable('@0'),
            'account-owner',
        ));
        $this->saveIds[] = $saveId;

        $foreignSession = ['account_id' => 'account-other'];
        $foreignMenu = $this->application->handle('GET', '/', ['page' => 'menu'], [], $foreignSession);
        self::assertStringNotContainsString($saveId, $foreignMenu['body']);

        $foreignHome = $this->application->handle('GET', '/', ['page' => 'home', 'save' => $saveId], [], $foreignSession);
        self::assertSame(303, $foreignHome['status']);
        self::assertSame('/?page=menu', $foreignHome['headers']['Location']);

        $foreignAction = $this->application->handle('POST', '/', [], [
            'action' => 'set_training',
            'save' => $saveId,
            'focus' => 'passing',
            'token' => 'tampered',
        ], $foreignSession);
        self::assertSame(303, $foreignAction['status']);
        self::assertSame('/?page=menu', $foreignAction['headers']['Location']);

        $ownerSession = ['account_id' => 'account-owner'];
        $ownerMenu = $this->application->handle('GET', '/', ['page' => 'menu'], [], $ownerSession);
        self::assertStringContainsString($saveId, $ownerMenu['body']);
        self::assertStringContainsString('Career unavailable', $ownerMenu['body']);
        self::assertStringNotContainsString('SQL', $ownerMenu['body']);
    }

    public function testSaveScopedActionTokenCannotBeReplayedAgainstAnotherSave(): void
    {
        $saveA = $this->saveId('token-a');
        $saveB = $this->saveId('token-b');
        $services = (new Bootstrap())->create($this->root, ['APP_ENV' => 'test']);
        $timestamp = new DateTimeImmutable('@0');
        $services->saveStore()->create(SaveMetadata::create($saveA, 'Token A', new SimulationTime(0), $timestamp, 'same-account'));
        $services->saveStore()->create(SaveMetadata::create($saveB, 'Token B', new SimulationTime(0), $timestamp, 'same-account'));
        $this->saveIds[] = $saveA;
        $this->saveIds[] = $saveB;

        $session = [
            'account_id' => 'same-account',
            'web_tokens' => ['set_training_' . $saveA => 'token-a'],
        ];
        $response = $this->application->handle('POST', '/', [], [
            'action' => 'set_training',
            'save' => $saveB,
            'focus' => 'passing',
            'token' => 'token-a',
        ], $session);

        self::assertSame(303, $response['status']);
        self::assertSame('token-a', $session['web_tokens']['set_training_' . $saveA]);
    }

    private function saveId(string $suffix): string
    {
        return 'p3017-' . $suffix . '-' . bin2hex(random_bytes(4));
    }
}
