<?php

declare(strict_types=1);

use PurpleOrca\Doctor\Checks\NPlusOneQueryCheck;
use PurpleOrca\Doctor\Enums\Status;

function create_n1_fixture_root(): string
{
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'laravel-doctor-n1-' . uniqid('', true);

    mkdir($root . '/app/Models', 0777, true);
    mkdir($root . '/app/Http/Controllers', 0777, true);
    mkdir($root . '/app/Services', 0777, true);
    mkdir($root . '/resources/views', 0777, true);

    return $root;
}

function write_n1_file(string $root, string $path, string $contents): void
{
    $fullPath = $root . '/' . ltrim($path, '/');
    @mkdir(dirname($fullPath), 0777, true);
    file_put_contents($fullPath, $contents);
}

it('does not flag casted date formatting inside a loop', function () {
    $root = create_n1_fixture_root();

    write_n1_file($root, 'app/Models/Payment.php', <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $casts = [
        'payment_date' => 'date',
    ];
}
PHP);

    write_n1_file($root, 'app/Services/DashboardService.php', <<<'PHP'
<?php

namespace App\Services;

use App\Models\Payment;

class DashboardService
{
    public function getCashflowData(): array
    {
        $payments = Payment::query()->get();

        foreach ($payments as $payment) {
            $payment->payment_date->format('Y-m-d');
        }

        return [];
    }
}
PHP);

    $check = new NPlusOneQueryCheck($root);
    $result = $check->run();

    expect($result->status)->toBe(Status::Pass);
    expect($result->message)->toBe('No obvious N+1 query patterns found');
});

it('flags obvious relation-in-loop N+1 queries in PHP source', function () {
    $root = create_n1_fixture_root();

    write_n1_file($root, 'app/Models/Post.php', <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Post extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
PHP);

    write_n1_file($root, 'app/Http/Controllers/PostController.php', <<<'PHP'
<?php

namespace App\Http\Controllers;

use App\Models\Post;

class PostController
{
    public function index(): void
    {
        $posts = Post::all();

        foreach ($posts as $post) {
            echo $post->user->name;
        }
    }
}
PHP);

    $check = new NPlusOneQueryCheck($root);
    $result = $check->run();

    expect($result->status)->toBe(Status::Fail);
    expect($result->message)->toContain('PostController.php');
    expect($result->message)->toContain('$post->user->name');
    expect($result->advice)->toContain('Eager load');
});

it('passes when the relation is eager loaded before the loop', function () {
    $root = create_n1_fixture_root();

    write_n1_file($root, 'app/Models/Post.php', <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Post extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
PHP);

    write_n1_file($root, 'app/Http/Controllers/PostController.php', <<<'PHP'
<?php

namespace App\Http\Controllers;

use App\Models\Post;

class PostController
{
    public function index(): void
    {
        $posts = Post::query()->with('user')->get();

        foreach ($posts as $post) {
            echo $post->user->name;
        }
    }
}
PHP);

    $check = new NPlusOneQueryCheck($root);
    $result = $check->run();

    expect($result->status)->toBe(Status::Pass);
});

it('passes when loadMissing is used before the loop', function () {
    $root = create_n1_fixture_root();

    write_n1_file($root, 'app/Models/Post.php', <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Post extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
PHP);

    write_n1_file($root, 'app/Http/Controllers/PostController.php', <<<'PHP'
<?php

namespace App\Http\Controllers;

use App\Models\Post;

class PostController
{
    public function index(): void
    {
        $posts = Post::all();
        $posts->loadMissing('user');

        foreach ($posts as $post) {
            echo $post->user->name;
        }
    }
}
PHP);

    $check = new NPlusOneQueryCheck($root);
    $result = $check->run();

    expect($result->status)->toBe(Status::Pass);
});

it('flags obvious N+1 queries in Blade templates with inline data source', function () {
    $root = create_n1_fixture_root();

    write_n1_file($root, 'app/Models/Post.php', <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Post extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
PHP);

    write_n1_file($root, 'resources/views/posts.blade.php', <<<'BLADE'
@php($posts = Post::all())

@foreach($posts as $post)
    {{ $post->user->name }}
@endforeach
BLADE);

    $check = new NPlusOneQueryCheck($root);
    $result = $check->run();

    expect($result->status)->toBe(Status::Fail);
    expect($result->message)->toContain('posts.blade.php');
    expect($result->message)->toContain('$post->user->name');
});
