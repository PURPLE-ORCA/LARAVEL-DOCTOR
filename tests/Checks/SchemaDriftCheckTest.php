<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PurpleOrca\Doctor\Checks\SchemaDriftCheck;
use PurpleOrca\Doctor\Enums\Status;

function create_schema_fixture_root(): string
{
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'laravel-doctor-schema-' . uniqid('', true);

    mkdir($root . '/app/Models', 0777, true);
    mkdir($root . '/app/Http/Controllers', 0777, true);
    mkdir($root . '/app/Services', 0777, true);
    mkdir($root . '/routes', 0777, true);

    return $root;
}

function write_schema_file(string $root, string $path, string $contents): void
{
    $fullPath = $root . '/' . ltrim($path, '/');
    @mkdir(dirname($fullPath), 0777, true);
    file_put_contents($fullPath, $contents);
}

beforeEach(function () {
    Schema::dropIfExists('users');
});

afterEach(function () {
    Schema::dropIfExists('users');
});

it('flags missing columns referenced by eloquent queries', function () {
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('email');
    });

    $root = create_schema_fixture_root();

    write_schema_file($root, 'app/Models/User.php', <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
}
PHP);

    write_schema_file($root, 'app/Http/Controllers/UserController.php', <<<'PHP'
<?php

namespace App\Http\Controllers;

use App\Models\User;

class UserController
{
    public function index(): void
    {
        User::query()->where('role_slug', 'admin')->get();
    }
}
PHP);

    $check = new SchemaDriftCheck($root);
    $result = $check->run();

    expect($result->status)->toBe(Status::Fail);
    expect($result->message)->toContain('UserController.php');
    expect($result->message)->toContain('users.role_slug');
    expect($result->advice)->toContain('missing migration');
});

it('flags missing columns on tracked query builders', function () {
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('email');
    });

    $root = create_schema_fixture_root();

    write_schema_file($root, 'app/Services/UserLookup.php', <<<'PHP'
<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class UserLookup
{
    public function run(): void
    {
        $query = DB::table('users');
        $query->where('is_staff', true)->get();
    }
}
PHP);

    $check = new SchemaDriftCheck($root);
    $result = $check->run();

    expect($result->status)->toBe(Status::Fail);
    expect($result->message)->toContain('UserLookup.php');
    expect($result->message)->toContain('users.is_staff');
});

it('passes when referenced columns exist', function () {
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('email');
    });

    $root = create_schema_fixture_root();

    write_schema_file($root, 'app/Models/User.php', <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
}
PHP);

    write_schema_file($root, 'app/Http/Controllers/UserController.php', <<<'PHP'
<?php

namespace App\Http\Controllers;

use App\Models\User;

class UserController
{
    public function index(): void
    {
        User::query()
            ->select('id', 'email')
            ->where('email', 'test@example.com')
            ->orderBy('name')
            ->get();
    }
}
PHP);

    $check = new SchemaDriftCheck($root);
    $result = $check->run();

    expect($result->status)->toBe(Status::Pass);
    expect($result->message)->toBe('No obvious schema drift patterns found');
});

it('ignores dynamic column names to stay low-noise', function () {
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('email');
    });

    $root = create_schema_fixture_root();

    write_schema_file($root, 'app/Models/User.php', <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
}
PHP);

    write_schema_file($root, 'app/Http/Controllers/UserController.php', <<<'PHP'
<?php

namespace App\Http\Controllers;

use App\Models\User;

class UserController
{
    public function index(string $column): void
    {
        User::query()->orderBy($column)->get();
    }
}
PHP);

    $check = new SchemaDriftCheck($root);
    $result = $check->run();

    expect($result->status)->toBe(Status::Pass);
});
