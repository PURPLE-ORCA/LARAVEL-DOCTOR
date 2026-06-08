<?php

declare(strict_types=1);

use PurpleOrca\Doctor\Checks\NPlusOneQueryCheck;
use PurpleOrca\Doctor\Enums\Status;

function create_n1_fixture_root(): string
{
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'laravel-doctor-n1-' . uniqid('', true);

    mkdir($root . '/app/Http/Controllers', 0777, true);
    mkdir($root . '/resources/views', 0777, true);

    return $root;
}

it('flags obvious N+1 queries in PHP source', function () {
    $root = create_n1_fixture_root();

    file_put_contents($root . '/app/Http/Controllers/PostController.php', <<<'PHP'
<?php

$posts = Post::all();

foreach ($posts as $post) {
    echo $post->user->name;
}
PHP);

    $check = new NPlusOneQueryCheck($root);
    $result = $check->run();

    expect($result->status)->toBe(Status::Fail);
    expect($result->message)->toContain('PostController.php:5');
    expect($result->message)->toContain('$post->user->name');
    expect($result->advice)->toContain('Eager load');
});

it('passes when the relation is eager loaded before the loop', function () {
    $root = create_n1_fixture_root();

    file_put_contents($root . '/app/Http/Controllers/PostController.php', <<<'PHP'
<?php

$posts = Post::query()->with('user')->get();

foreach ($posts as $post) {
    echo $post->user->name;
}
PHP);

    $check = new NPlusOneQueryCheck($root);
    $result = $check->run();

    expect($result->status)->toBe(Status::Pass);
});

it('passes when loadMissing is used before the loop', function () {
    $root = create_n1_fixture_root();

    file_put_contents($root . '/app/Http/Controllers/PostController.php', <<<'PHP'
<?php

$posts = Post::all();
$posts->loadMissing('user');

foreach ($posts as $post) {
    echo $post->user->name;
}
PHP);

    $check = new NPlusOneQueryCheck($root);
    $result = $check->run();

    expect($result->status)->toBe(Status::Pass);
});

it('flags obvious N+1 queries in Blade templates with inline data source', function () {
    $root = create_n1_fixture_root();

    file_put_contents($root . '/resources/views/posts.blade.php', <<<'BLADE'
@php($posts = Post::all())

@foreach($posts as $post)
    {{ $post->user->name }}
@endforeach
BLADE);

    $check = new NPlusOneQueryCheck($root);
    $result = $check->run();

    expect($result->status)->toBe(Status::Fail);
    expect($result->message)->toContain('posts.blade.php:3');
    expect($result->message)->toContain('$post->user->name');
});
