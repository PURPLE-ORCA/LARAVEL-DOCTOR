<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PurpleOrca\Doctor\Checks\UniqueConstraintCoverageCheck;
use PurpleOrca\Doctor\Enums\Status;

function create_unique_constraint_fixture_root(): string
{
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'laravel-doctor-unique-' . uniqid('', true);

    mkdir($root . '/app/Models', 0777, true);
    mkdir($root . '/app/Http/Requests', 0777, true);

    return $root;
}

function write_unique_constraint_file(string $root, string $path, string $contents): void
{
    $fullPath = $root . '/' . ltrim($path, '/');
    @mkdir(dirname($fullPath), 0777, true);
    file_put_contents($fullPath, $contents);
}

beforeEach(function () {
    Schema::dropIfExists('catalogs');
    Schema::dropIfExists('users');
});

afterEach(function () {
    Schema::dropIfExists('catalogs');
    Schema::dropIfExists('users');
});

it('flags validator-only unique string rules without database unique coverage', function () {
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('email');
    });

    $root = create_unique_constraint_fixture_root();

    write_unique_constraint_file($root, 'app/Http/Requests/StoreUserRequest.php', <<<'PHP'
<?php

namespace App\Http\Requests;

class StoreUserRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'unique:users'],
        ];
    }
}
PHP);

    $check = new UniqueConstraintCoverageCheck($root);
    $result = $check->run();

    expect($result->status)->toBe(Status::Fail);
    expect($result->message)->toContain('StoreUserRequest.php');
    expect($result->message)->toContain('users.email');
    expect($result->advice)->toContain('unique index');
});

it('passes when a simple unique validator is backed by a unique index', function () {
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('email')->unique();
    });

    $root = create_unique_constraint_fixture_root();

    write_unique_constraint_file($root, 'app/Http/Requests/StoreUserRequest.php', <<<'PHP'
<?php

namespace App\Http\Requests;

class StoreUserRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'unique:users'],
        ];
    }
}
PHP);

    $check = new UniqueConstraintCoverageCheck($root);
    $result = $check->run();

    expect($result->status)->toBe(Status::Pass);
    expect($result->message)->toContain('No obvious validator/database unique constraint drift found');
});

it('resolves Rule unique model targets on multiline request rules', function () {
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('email');
    });

    $root = create_unique_constraint_fixture_root();

    write_unique_constraint_file($root, 'app/Models/User.php', <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
}
PHP);

    write_unique_constraint_file($root, 'app/Http/Requests/ProfileUpdateRequest.php', <<<'PHP'
<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest
{
    public function rules(): array
    {
        return [
            'email' => [
                'required',
                'email:rfc,dns',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
        ];
    }
}
PHP);

    $check = new UniqueConstraintCoverageCheck($root);
    $result = $check->run();

    expect($result->status)->toBe(Status::Fail);
    expect($result->message)->toContain('ProfileUpdateRequest.php');
    expect($result->message)->toContain('users.email');
});

it('ignores scoped Rule unique checks that need composite index reasoning', function () {
    Schema::create('catalogs', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('tenant_id');
        $table->string('tag');
        $table->unique(['tenant_id', 'tag']);
    });

    $root = create_unique_constraint_fixture_root();

    write_unique_constraint_file($root, 'app/Http/Requests/StoreCatalogRequest.php', <<<'PHP'
<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class StoreCatalogRequest
{
    public function rules(): array
    {
        return [
            'tag' => [
                'required',
                Rule::unique('catalogs', 'tag')->where(fn ($query) => $query->where('tenant_id', 1)),
            ],
        ];
    }
}
PHP);

    $check = new UniqueConstraintCoverageCheck($root);
    $result = $check->run();

    expect($result->status)->toBe(Status::Pass);
});
