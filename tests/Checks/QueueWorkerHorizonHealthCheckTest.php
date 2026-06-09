<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PurpleOrca\Doctor\Checks\QueueWorkerHorizonHealthCheck;
use PurpleOrca\Doctor\Enums\Status;

function make_queue_health_fixture(array $files): string
{
    $root = sys_get_temp_dir().'/laravel-doctor-queue-'.bin2hex(random_bytes(6));

    foreach ($files as $relativePath => $contents) {
        $path = $root.'/'.$relativePath;
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, $contents);
    }

    if (! is_dir($root.'/app')) {
        mkdir($root.'/app', 0777, true);
    }

    return $root;
}

function delete_queue_health_fixture(string $root): void
{
    if (! is_dir($root)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
            continue;
        }

        unlink($item->getPathname());
    }

    rmdir($root);
}

function create_queue_tables(): void
{
    Schema::dropIfExists('jobs');
    Schema::dropIfExists('failed_jobs');

    Schema::create('jobs', function (Blueprint $table) {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    Schema::create('failed_jobs', function (Blueprint $table) {
        $table->id();
        $table->string('uuid')->unique();
        $table->text('connection');
        $table->text('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at')->useCurrent();
    });
}

function drop_queue_tables(): void
{
    Schema::dropIfExists('jobs');
    Schema::dropIfExists('failed_jobs');
}

beforeEach(function () {
    $this->queueFixtureRoot = null;

    config()->set('app.env', 'production');
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.retry_after', 90);
    config()->set('horizon.environments', null);

    drop_queue_tables();
});

afterEach(function () {
    drop_queue_tables();

    if (is_string($this->queueFixtureRoot)) {
        delete_queue_health_fixture($this->queueFixtureRoot);
    }
});

it('passes for a healthy database queue with queued work usage', function () {
    create_queue_tables();

    $this->queueFixtureRoot = make_queue_health_fixture([
        'app/Jobs/SendWelcomeEmail.php' => <<<'PHP'
<?php

use Illuminate\Contracts\Queue\ShouldQueue;

final class SendWelcomeEmail implements ShouldQueue {}
PHP,
    ]);

    $check = new QueueWorkerHorizonHealthCheck($this->queueFixtureRoot);
    $result = $check->run();

    expect($check->name())->toBe('Queue Worker / Horizon Health');
    expect($check->category())->toBe('infrastructure');
    expect($result->status)->toBe(Status::Pass);
    expect($result->message)->toBe('No obvious queue worker or Horizon health risks found');
});

it('fails when queued work exists but production uses sync queueing', function () {
    config()->set('queue.default', 'sync');

    $this->queueFixtureRoot = make_queue_health_fixture([
        'app/Jobs/SendWelcomeEmail.php' => <<<'PHP'
<?php

use Illuminate\Contracts\Queue\ShouldQueue;

final class SendWelcomeEmail implements ShouldQueue {}
PHP,
    ]);

    $check = new QueueWorkerHorizonHealthCheck($this->queueFixtureRoot);
    $result = $check->run();

    expect($result->status)->toBe(Status::Fail);
    expect($result->message)->toContain('QUEUE_CONNECTION=sync');
    expect($result->message)->toContain('app/Jobs/SendWelcomeEmail.php');
});

it('fails when database queue tables are missing', function () {
    $this->queueFixtureRoot = make_queue_health_fixture([
        'app/Jobs/SendWelcomeEmail.php' => <<<'PHP'
<?php

use Illuminate\Contracts\Queue\ShouldQueue;

final class SendWelcomeEmail implements ShouldQueue {}
PHP,
    ]);

    $check = new QueueWorkerHorizonHealthCheck($this->queueFixtureRoot);
    $result = $check->run();

    expect($result->status)->toBe(Status::Fail);
    expect($result->message)->toContain('required queue tables are missing');
    expect($result->message)->toContain('jobs');
    expect($result->message)->toContain('failed_jobs');
});

it('fails when reserved jobs look stuck', function () {
    create_queue_tables();

    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => '{}',
        'attempts' => 1,
        'reserved_at' => time() - 600,
        'available_at' => time() - 700,
        'created_at' => time() - 700,
    ]);

    $this->queueFixtureRoot = make_queue_health_fixture([
        'app/Jobs/SendWelcomeEmail.php' => <<<'PHP'
<?php

use Illuminate\Contracts\Queue\ShouldQueue;

final class SendWelcomeEmail implements ShouldQueue {}
PHP,
    ]);

    $check = new QueueWorkerHorizonHealthCheck($this->queueFixtureRoot);
    $result = $check->run();

    expect($result->status)->toBe(Status::Fail);
    expect($result->message)->toContain('look stuck');
});

it('warns when failed jobs are recorded', function () {
    create_queue_tables();

    DB::table('failed_jobs')->insert([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'boom',
        'failed_at' => now(),
    ]);

    $this->queueFixtureRoot = make_queue_health_fixture([
        'app/Jobs/SendWelcomeEmail.php' => <<<'PHP'
<?php

use Illuminate\Contracts\Queue\ShouldQueue;

final class SendWelcomeEmail implements ShouldQueue {}
PHP,
    ]);

    $check = new QueueWorkerHorizonHealthCheck($this->queueFixtureRoot);
    $result = $check->run();

    expect($result->status)->toBe(Status::Warn);
    expect($result->message)->toContain('failed queue job');
});

it('fails when Horizon appears enabled on a non redis queue backend', function () {
    config()->set('queue.default', 'database');
    config()->set('horizon.environments', ['production' => ['supervisor-1' => []]]);
    create_queue_tables();

    $this->queueFixtureRoot = make_queue_health_fixture([
        'app/Jobs/SendWelcomeEmail.php' => <<<'PHP'
<?php

use Illuminate\Contracts\Queue\ShouldQueue;

final class SendWelcomeEmail implements ShouldQueue {}
PHP,
    ]);

    $check = new QueueWorkerHorizonHealthCheck($this->queueFixtureRoot);
    $result = $check->run();

    expect($result->status)->toBe(Status::Fail);
    expect($result->message)->toContain('Horizon appears to be enabled');
    expect($result->message)->toContain('redis');
});
