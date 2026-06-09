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
    Schema::dropIfExists('curriculum_items');
    Schema::dropIfExists('contents');
    Schema::dropIfExists('users');
});

afterEach(function () {
    Schema::dropIfExists('curriculum_items');
    Schema::dropIfExists('contents');
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

it('attributes nested subquery columns to the inner table', function () {
    Schema::dropIfExists('curriculum_items');
    Schema::dropIfExists('contents');

    Schema::create('contents', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('curriculum_item_id');
    });

    Schema::create('curriculum_items', function (Blueprint $table) {
        $table->id();
    });

    $root = create_schema_fixture_root();

    write_schema_file($root, 'app/Models/Content.php', <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Content extends Model
{
}
PHP);

    write_schema_file($root, 'app/Models/CurriculumItem.php', <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CurriculumItem extends Model
{
}
PHP);

    write_schema_file($root, 'app/Actions/Curriculum/BulkDeleteCurriculumNodesAction.php', <<<'PHP'
<?php

namespace App\Actions\Curriculum;

use App\Models\Content;
use App\Models\CurriculumItem;

class BulkDeleteCurriculumNodesAction
{
    public function handle($formation, $contentIds): void
    {
        Content::query()
            ->whereIn('id', $contentIds)
            ->whereIn(
                'curriculum_item_id',
                CurriculumItem::query()->where('formation_id', $formation->id)->select('id')
            )
            ->delete();
    }
}
PHP);

    $check = new SchemaDriftCheck($root);
    $result = $check->run();

    expect($result->status)->toBe(Status::Fail);
    expect($result->message)->toContain('BulkDeleteCurriculumNodesAction.php');
    expect($result->message)->toContain('curriculum_items.formation_id');
    expect($result->message)->not->toContain('contents.formation_id');
});

it('does not attribute eager-load callback columns to the outer table', function () {
    Schema::dropIfExists('curriculum_items');
    Schema::dropIfExists('contents');

    Schema::create('curriculum_items', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('formation_id');
        $table->integer('position')->default(0);
    });

    Schema::create('contents', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('curriculum_item_id');
        $table->integer('order')->default(0);
    });

    $root = create_schema_fixture_root();

    write_schema_file($root, 'app/Models/Content.php', <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Content extends Model
{
}
PHP);

    write_schema_file($root, 'app/Models/CurriculumItem.php', <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CurriculumItem extends Model
{
    public function contents()
    {
        return $this->hasMany(Content::class);
    }
}
PHP);

    write_schema_file($root, 'app/Http/Controllers/Admin/CurriculumItemController.php', <<<'PHP'
<?php

namespace App\Http\Controllers\Admin;

use App\Models\CurriculumItem;

class CurriculumItemController
{
    public function index($formation): void
    {
        CurriculumItem::where('formation_id', $formation->id)
            ->with(['contents' => fn ($query) => $query->orderBy('order')->with('contentType')])
            ->orderBy('position')
            ->get();
    }
}
PHP);

    $check = new SchemaDriftCheck($root);
    $result = $check->run();

    expect($result->status)->toBe(Status::Pass);
    expect($result->message)->toBe('No obvious schema drift patterns found');
});

it('flags missing columns inside eager-load callbacks against the relation table', function () {
    Schema::dropIfExists('curriculum_items');
    Schema::dropIfExists('contents');

    Schema::create('curriculum_items', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('formation_id');
        $table->integer('position')->default(0);
    });

    Schema::create('contents', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('curriculum_item_id');
    });

    $root = create_schema_fixture_root();

    write_schema_file($root, 'app/Models/Content.php', <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Content extends Model
{
}
PHP);

    write_schema_file($root, 'app/Models/CurriculumItem.php', <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CurriculumItem extends Model
{
    public function contents()
    {
        return $this->hasMany(Content::class);
    }
}
PHP);

    write_schema_file($root, 'app/Http/Controllers/Admin/CurriculumItemController.php', <<<'PHP'
<?php

namespace App\Http\Controllers\Admin;

use App\Models\CurriculumItem;

class CurriculumItemController
{
    public function index($formation): void
    {
        CurriculumItem::where('formation_id', $formation->id)
            ->with(['contents' => fn ($query) => $query->orderBy('missing_order')])
            ->orderBy('position')
            ->get();
    }
}
PHP);

    $check = new SchemaDriftCheck($root);
    $result = $check->run();

    expect($result->status)->toBe(Status::Fail);
    expect($result->message)->toContain('CurriculumItemController.php');
    expect($result->message)->toContain('contents.missing_order');
    expect($result->message)->not->toContain('curriculum_items.missing_order');
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
