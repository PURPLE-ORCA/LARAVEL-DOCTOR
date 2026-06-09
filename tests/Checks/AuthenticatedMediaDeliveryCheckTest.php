<?php

declare(strict_types=1);

use Illuminate\Routing\RouteCollection;
use PurpleOrca\Doctor\Checks\AuthenticatedMediaDeliveryCheck;
use PurpleOrca\Doctor\Enums\Status;

function reset_authenticated_media_routes(): void
{
    app('router')->setRoutes(new RouteCollection);
}

function make_authenticated_media_fixture(array $files): string
{
    $root = sys_get_temp_dir().'/laravel-doctor-media-'.bin2hex(random_bytes(6));

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

function delete_authenticated_media_fixture(string $root): void
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

beforeEach(function () {
    reset_authenticated_media_routes();
    $this->mediaFixtureRoot = null;
});

afterEach(function () {
    reset_authenticated_media_routes();

    if (is_string($this->mediaFixtureRoot)) {
        delete_authenticated_media_fixture($this->mediaFixtureRoot);
    }
});

it('passes for guarded media routes backed by signed delivery helpers', function () {
    $router = app('router');

    $router->middleware(['auth', 'verified'])->get('/student/content/{content}/video-url', fn () => 'ok')->name('student.content.video-url');

    $this->mediaFixtureRoot = make_authenticated_media_fixture([
        'app/Services/BunnyCdnService.php' => <<<'PHP'
<?php

final class BunnyCdnService
{
    private function streamBaseUrl(): string
    {
        return 'https://video.bunnycdn.com';
    }

    private function embedHost(): string
    {
        return 'https://player.mediadelivery.net';
    }

    public function getSecureEmbedUrl(string $videoId): string
    {
        $expiry = time() + 3600;
        $signature = hash('sha256', $videoId.$expiry);

        return $this->embedHost()."/embed/library/{$videoId}?token={$signature}&expires={$expiry}";
    }
}
PHP,
    ]);

    $check = new AuthenticatedMediaDeliveryCheck($this->mediaFixtureRoot);
    $result = $check->run();

    expect($check->name())->toBe('Authenticated Media Delivery');
    expect($check->category())->toBe('security');
    expect($result->status)->toBe(Status::Pass);
    expect($result->message)->toBe('No obvious authenticated media delivery risks found');
});

it('flags public media routes without meaningful access control', function () {
    $router = app('router');

    $router->get('/content/{content}/video-url', fn () => 'ok')->name('content.video-url');

    $this->mediaFixtureRoot = make_authenticated_media_fixture([]);

    $check = new AuthenticatedMediaDeliveryCheck($this->mediaFixtureRoot);
    $result = $check->run();

    expect($result->status)->toBe(Status::Fail);
    expect($result->message)->toContain('missing meaningful access control');
    expect($result->message)->toContain('content.video-url');
});

it('flags referer based media authorization', function () {
    $router = app('router');

    $router->middleware('auth')->get('/admin/videos/{video}/stream', fn () => 'ok')->name('admin.videos.stream');

    $this->mediaFixtureRoot = make_authenticated_media_fixture([
        'app/Http/Controllers/MediaController.php' => <<<'PHP'
<?php

use Illuminate\Http\Request;

final class MediaController
{
    public function stream(Request $request): string
    {
        $referer = $request->header('Referer');

        if ($referer === 'https://example.com/player') {
            return 'https://player.mediadelivery.net/embed/library/video-id';
        }

        return 'denied';
    }
}
PHP,
    ]);

    $check = new AuthenticatedMediaDeliveryCheck($this->mediaFixtureRoot);
    $result = $check->run();

    expect($result->status)->toBe(Status::Fail);
    expect($result->message)->toContain('Referer-based media authorization detected');
    expect($result->message)->toContain('MediaController.php');
});

it('flags unsigned bunny style media url generators', function () {
    $router = app('router');

    $router->middleware('auth')->get('/student/content/{content}/video-url', fn () => 'ok')->name('student.content.video-url');

    $this->mediaFixtureRoot = make_authenticated_media_fixture([
        'app/Services/BunnyCdnService.php' => <<<'PHP'
<?php

final class BunnyCdnService
{
    public function getVideoUrl(string $videoId): string
    {
        return "https://player.mediadelivery.net/embed/library/{$videoId}";
    }
}
PHP,
    ]);

    $check = new AuthenticatedMediaDeliveryCheck($this->mediaFixtureRoot);
    $result = $check->run();

    expect($result->status)->toBe(Status::Fail);
    expect($result->message)->toContain('Unsigned media URL generator detected');
    expect($result->message)->toContain('getVideoUrl');
});
