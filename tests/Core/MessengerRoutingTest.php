<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Federation\Application\DeliverActivity;
use App\Federation\Application\ProcessInboxActivity;
use App\Import\Instagram\Application\ImportInstagramBatch;
use App\Media\Application\ProcessVideo;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Files de production : les échanges avec le fédivers ne doivent jamais attendre derrière un import ou une vidéo.
 * En test, les messages passent par d'autres transports : on lit donc la configuration elle-même.
 */
final class MessengerRoutingTest extends TestCase
{
    public function testFederationVideosAndBackgroundTasksHaveTheirOwnQueues(): void
    {
        /** @var array{framework: array{messenger: array{routing: array<string, string>, transports: array<string, mixed>}}} $config */
        $config = Yaml::parseFile(__DIR__.'/../../config/packages/messenger.yaml');
        $messenger = $config['framework']['messenger'];

        self::assertSame('federation', $messenger['routing'][DeliverActivity::class]);
        self::assertSame('federation', $messenger['routing'][ProcessInboxActivity::class]);
        self::assertSame('media', $messenger['routing'][ProcessVideo::class]);
        self::assertSame('async', $messenger['routing'][ImportInstagramBatch::class]);
        self::assertSame(['federation', 'async', 'media', 'failed', 'sync'], array_keys($messenger['transports']));
    }

    public function testWorkersEmptyTheFederationQueueFirst(): void
    {
        $supervisor = (string) file_get_contents(__DIR__.'/../../docker/frankenphp/messenger-supervisor.sh');

        self::assertStringContainsString('TRANSPORTS="${MESSENGER_TRANSPORTS:-federation async media scheduler_default}"', $supervisor);
        self::assertStringContainsString('TRANSPORTS="${MESSENGER_TRANSPORTS:-federation async media}"', $supervisor);
    }
}
