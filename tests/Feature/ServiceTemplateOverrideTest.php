<?php

use App\Livewire\Settings\ServiceTemplates;
use App\Models\InstanceSettings;
use App\Models\ServiceTemplateOverride;
use App\Models\Team;
use App\Models\User;
use App\Support\ServiceTemplateCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    config(['cache.default' => 'array']);

    InstanceSettings::unguarded(
        fn () => InstanceSettings::query()->firstOrCreate(['id' => 0])
    );

    $this->rootTeam = Team::query()->find(0);
    if ($this->rootTeam === null) {
        $this->rootTeam = Team::factory()->make([
            'name' => 'Root Team',
            'personal_team' => false,
        ]);
        $this->rootTeam->id = 0;
        $this->rootTeam->save();
    }

    $this->owner = User::factory()->create();
    $this->admin = User::factory()->create();
    $this->member = User::factory()->create();
    $this->rootTeam->members()->attach($this->owner->id, ['role' => 'owner']);
    $this->rootTeam->members()->attach($this->admin->id, ['role' => 'admin']);
    $this->rootTeam->members()->attach($this->member->id, ['role' => 'member']);

    $this->composeDirectory = sys_get_temp_dir().'/coolify-service-templates-'.uniqid();
    mkdir($this->composeDirectory);
    file_put_contents($this->composeDirectory.'/odoo.yaml', "services:\n  odoo:\n    image: odoo:18\n");
    config(['constants.services.compose_path' => $this->composeDirectory]);
});

afterEach(function () {
    if (is_dir($this->composeDirectory)) {
        array_map('unlink', glob($this->composeDirectory.'/*') ?: []);
        rmdir($this->composeDirectory);
    }
});

function odooCompose(string $image = 'odoo:20'): string
{
    return "services:\n  odoo:\n    image: {$image}\n  postgresql:\n    image: postgres:16-alpine\n";
}

test('the instance owner saves a template and new services receive that compose', function () {
    $this->actingAs($this->owner);

    Livewire::test(ServiceTemplates::class)
        ->call('selectService', 'odoo')
        ->set('compose', odooCompose())
        ->call('save')
        ->assertHasNoErrors();

    $saved = base64_decode((string) data_get(get_service_templates(), 'odoo.compose'));

    expect($saved)->toContain('image: odoo:20')
        ->and(file_get_contents($this->composeDirectory.'/odoo.yaml'))->toContain('image: odoo:20')
        ->and((int) ServiceTemplateOverride::query()->where('name', 'odoo')->value('updated_by'))->toBe($this->owner->id);
});

test('restoring a template returns the catalog compose for the next service', function () {
    $this->actingAs($this->owner);
    $catalog = base64_decode((string) data_get(service_templates_from_catalog(), 'odoo.compose'));

    ServiceTemplateCatalog::save('odoo', odooCompose(), $this->owner->id);

    Livewire::test(ServiceTemplates::class)
        ->call('selectService', 'odoo')
        ->call('restore')
        ->assertSet('compose', ServiceTemplateCatalog::composeFor('odoo'));

    expect(ServiceTemplateOverride::query()->where('name', 'odoo')->exists())->toBeFalse()
        ->and(base64_decode((string) data_get(get_service_templates(), 'odoo.compose')))->toBe($catalog)
        ->and(file_get_contents($this->composeDirectory.'/odoo.yaml'))->toContain('image: odoo:18');
});

test('invalid compose is rejected and the catalog stays unchanged', function () {
    $this->actingAs($this->owner);
    $before = data_get(get_service_templates(), 'odoo.compose');

    Livewire::test(ServiceTemplates::class)
        ->call('selectService', 'odoo')
        ->set('compose', "services:\n  'bad;name':\n    image: nginx\n")
        ->call('save')
        ->assertHasErrors(['compose']);

    expect(data_get(get_service_templates(), 'odoo.compose'))->toBe($before)
        ->and(ServiceTemplateOverride::query()->count())->toBe(0);
});

test('a root team admin and a member cannot edit service templates', function (string $who) {
    $this->actingAs($this->{$who});

    Livewire::test(ServiceTemplates::class)->assertForbidden();
})->with(['admin', 'member']);

test('an owner of another team cannot edit service templates', function () {
    $team = Team::factory()->create();
    $owner = User::factory()->create();
    $team->members()->attach($owner->id, ['role' => 'owner']);

    $this->actingAs($owner);

    Livewire::test(ServiceTemplates::class)->assertForbidden();
});

test('the instance owner creates a custom template and it appears in launch options', function () {
    $this->actingAs($this->owner);

    $compose = "services:\n  app:\n    image: nginx:alpine\n    ports:\n      - \"80\"\n";

    Livewire::test(ServiceTemplates::class)
        ->call('startCreate')
        ->set('newName', 'My Worker')
        ->set('displayName', 'My Worker')
        ->set('category', 'Custom')
        ->set('compose', $compose)
        ->call('create')
        ->assertHasNoErrors()
        ->assertSet('serviceName', 'my-worker');

    $values = collect(ServiceTemplateCatalog::launchOptions())->pluck('value')->values()->all();

    expect(ServiceTemplateOverride::query()->where('name', 'my-worker')->where('is_custom', true)->exists())->toBeTrue()
        ->and(ServiceTemplateCatalog::composeFor('my-worker'))->toContain('image: nginx:alpine')
        ->and($values)->toContain('my-worker')
        ->and($values)->toContain('odoo')
        ->and(array_search('odoo', $values, true))->toBeLessThan(array_search('my-worker', $values, true));
});

test('hiding a custom template removes it from launch options', function () {
    $this->actingAs($this->owner);

    ServiceTemplateCatalog::create('hidden-app', "services:\n  app:\n    image: nginx:alpine\n", $this->owner->id, [
        'display_name' => 'Hidden App',
        'is_visible' => true,
    ]);

    expect(collect(ServiceTemplateCatalog::launchOptions())->pluck('value'))->toContain('hidden-app');

    ServiceTemplateCatalog::save('hidden-app', "services:\n  app:\n    image: nginx:alpine\n", $this->owner->id, [
        'is_visible' => false,
    ]);

    expect(collect(ServiceTemplateCatalog::launchOptions())->pluck('value'))->not->toContain('hidden-app');
});

test('odoo includes jupyter by default and custom templates can opt in', function () {
    $this->actingAs($this->owner);

    expect(ServiceTemplateCatalog::includesJupyter('odoo'))->toBeTrue();

    Livewire::test(ServiceTemplates::class)
        ->call('startCreate')
        ->set('newName', 'file-browser-app')
        ->set('displayName', 'Files App')
        ->set('includesJupyter', true)
        ->set('compose', "services:\n  app:\n    image: nginx:alpine\n    volumes:\n      - app-files:/data\n")
        ->call('create')
        ->assertHasNoErrors();

    expect(ServiceTemplateCatalog::includesJupyter('file-browser-app'))->toBeTrue()
        ->and((bool) ServiceTemplateOverride::query()->where('name', 'file-browser-app')->value('includes_jupyter'))->toBeTrue();

    Livewire::test(ServiceTemplates::class)
        ->call('selectService', 'file-browser-app')
        ->assertSet('includesJupyter', true)
        ->set('includesJupyter', false)
        ->call('save')
        ->assertHasNoErrors();

    expect(ServiceTemplateCatalog::includesJupyter('file-browser-app'))->toBeFalse();
});

test('saving odoo without an includes_jupyter override keeps jupyter on', function () {
    $this->actingAs($this->owner);

    Livewire::test(ServiceTemplates::class)
        ->call('selectService', 'odoo')
        ->assertSet('includesJupyter', true)
        ->set('compose', odooCompose())
        ->call('save')
        ->assertHasNoErrors();

    expect(ServiceTemplateCatalog::includesJupyter('odoo'))->toBeTrue()
        ->and((bool) ServiceTemplateOverride::query()->where('name', 'odoo')->value('includes_jupyter'))->toBeTrue();
});
