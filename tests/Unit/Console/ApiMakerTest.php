<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Console;

use FilesystemIterator;
use InvalidArgumentException;
use PhpOrbit\Console\ApiMaker;
use PhpOrbit\Console\AuthMaker;
use PhpOrbit\Console\Scaffold;
use PhpOrbit\Console\Variant;
use PhpOrbit\Http\Method;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class ApiMakerTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/orbit-api-maker-' . bin2hex(random_bytes(6));
        (new Scaffold(dirname(__DIR__, 3)))->create($this->project, Variant::Blank);
        mkdir($this->project . '/vendor');
        file_put_contents($this->project . '/vendor/autoload.php', '<?php $loader = require '
            . var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true) . ';'
            . '$loader->addPsr4("App\\\\", dirname(__DIR__)."/app/src/", true); return $loader;');
    }

    protected function tearDown(): void
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->project, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if ($item instanceof SplFileInfo) {
                $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
        }
        rmdir($this->project);
    }

    public function test_it_generates_an_editable_endpoint_without_rewriting_project_files(): void
    {
        $routes = file_get_contents($this->project . '/app/routes.php');
        $bootstrap = file_get_contents($this->project . '/app/bootstrap.php');
        $made = (new ApiMaker($this->project))->create('Admin/UserProfileController', Method::Post, 2);
        self::assertSame('/api/v2/admin/user-profile', $made->url);
        self::assertSame('app/src/Controllers/Api/V2/Admin/UserProfileController.php', $made->controllerPath);
        self::assertSame('app/api/v2/admin.user-profile.php', $made->declarationPath);
        self::assertSame($routes, file_get_contents($this->project . '/app/routes.php'));
        self::assertSame($bootstrap, file_get_contents($this->project . '/app/bootstrap.php'));
        self::assertStringContainsString('authenticated: true', (string) file_get_contents($this->project . '/' . $made->declarationPath));
        self::assertFileExists($this->project . '/public/assets/swagger/LICENSE');
        self::assertCount(1, glob($this->project . '/database/migrations/*_create_api.php') ?: []);
        self::assertFileDoesNotExist($this->project . '/storage/app.sqlite');
        foreach ($made->files as $file) {
            if (str_ends_with($file, '.php')) {
                exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($this->project . '/' . $file) . ' 2>&1', $output, $status);
                self::assertSame(0, $status, implode("\n", $output));
            }
        }
    }

    public function test_public_endpoints_work_automatically_in_both_process_models_and_swagger(): void
    {
        $maker = new ApiMaker($this->project);
        $maker->create('Ping', Method::Post, public: true);
        $maker->create('Ping', version: 2, public: true);
        $maker->create('PrivateProfile');
        $this->runProject(<<<'PHP'
            use PhpOrbit\Database\Migrator;
            use PhpOrbit\Database\Model;
            use PhpOrbit\Http\Method;
            use PhpOrbit\Tests\Support\Requests;

            for ($boot = 0; $boot < 2; $boot++) {
                Model::resetConnectionForTesting();
                $app = require __DIR__ . '/app/bootstrap.php';
                $app->container()->get(Migrator::class)->migrate();
                for ($request = 0; $request < 2; $request++) {
                    $response = $app->handle(Requests::of(Method::Post, '/api/v1/ping'));
                    if ($response->status->value !== 200 || json_decode($response->body, true) !== ['message' => 'It works']) {
                        throw new RuntimeException('Generated endpoint did not run: ' . $response->body);
                    }
                    if ($app->handle(Requests::get('/api/v2/ping'))->status->value !== 200) {
                        throw new RuntimeException('Second API version did not run.');
                    }
                }
                if ($app->handle(Requests::get('/api/v1/private-profile'))->status->value !== 401) {
                    throw new RuntimeException('Generated endpoint is not protected by default.');
                }
                $json = $app->handle(Requests::get('/api/openapi.json'));
                $document = json_decode($json->body, true, 512, JSON_THROW_ON_ERROR);
                foreach (['/api/v1/ping', '/api/v2/ping', '/api/v1/private-profile'] as $path) {
                    if (!isset($document['paths'][$path])) {
                        throw new RuntimeException('Missing OpenAPI path: ' . $path);
                    }
                }
                $docs = $app->handle(Requests::get('/api/docs'));
                if ($docs->status->value !== 200 || !str_contains($docs->body, '/assets/js/swagger.js')) {
                    throw new RuntimeException('Swagger documentation is unavailable.');
                }
                if ($app->handle(Requests::get('/assets/swagger/swagger-ui-bundle.js'))->status->value !== 200) {
                    throw new RuntimeException('Local Swagger assets are unavailable.');
                }
            }
            for ($request = 0; $request < 56; $request++) {
                if ($app->handle(Requests::of(Method::Post, '/api/v1/ping'))->status->value !== 200) {
                    throw new RuntimeException('Generated rate limit rejected a request too early.');
                }
            }
            if ($app->handle(Requests::of(Method::Post, '/api/v1/ping'))->status->value !== 429) {
                throw new RuntimeException('Generated endpoint did not enforce its default rate limit.');
            }
            $declaration = __DIR__ . '/app/api/v2/ping.php';
            $source = file_get_contents($declaration);
            file_put_contents($declaration, '<?php throw new RuntimeException("Declarations must not run per request.");');
            try {
                if ($app->handle(Requests::get('/api/openapi.json'))->status->value !== 200
                    || $app->handle(Requests::get('/api/v2/ping'))->status->value !== 200) {
                    throw new RuntimeException('Boot snapshot changed during the worker lifetime.');
                }
            } finally {
                file_put_contents($declaration, $source);
            }
            PHP);
    }

    public function test_auth_can_be_added_before_or_after_endpoint_generation_without_duplicate_schema(): void
    {
        $maker = new ApiMaker($this->project);
        $maker->create('BeforeAuth', public: true);
        (new AuthMaker($this->project))->create();
        $maker->create('AfterAuth');
        $this->runProject(<<<'PHP'
            use App\AuthKit\Accounts;
            use PhpOrbit\Api\Tokens;
            use PhpOrbit\Auth\PasswordHasher;
            use PhpOrbit\Database\Connection;
            use PhpOrbit\Database\Migrator;
            use PhpOrbit\Http\Method;
            use PhpOrbit\Tests\Support\Requests;

            $app = require __DIR__ . '/app/bootstrap.php';
            $migrator = $app->container()->get(Migrator::class);
            $migrator->migrate();
            $app->container()->get(Connection::class)->query('auth_users')->insert([
                'email' => 'cli@example.test', 'email_key' => Accounts::emailKey('cli@example.test'),
                'display_name' => 'CLI user',
                'password_hash' => $app->container()->get(PasswordHasher::class)->hash('correct-horse-battery'),
                'is_admin' => 0, 'blocked' => 0,
            ]);
            $user = $app->container()->get(Accounts::class)->findByEmail('cli@example.test');
            if ($user === null) {
                throw new RuntimeException('Synthetic account was not created.');
            }
            $token = $app->container()->get(Tokens::class)->personal($user, 'CLI');
            foreach ([null, $token->value, null, $token->value] as $bearer) {
                $headers = $bearer === null ? [] : ['Authorization' => 'Bearer ' . $bearer];
                $response = $app->handle(Requests::of(Method::Get, '/api/v1/after-auth', $headers));
                if ($response->status->value !== ($bearer === null ? 401 : 200)) {
                    throw new RuntimeException('Authentication or worker isolation failed: ' . $response->body);
                }
            }
            $doc = json_decode($app->handle(Requests::get('/api/openapi.json'))->body, true, 512, JSON_THROW_ON_ERROR);
            foreach (['/api/v1/me', '/api/v1/before-auth', '/api/v1/after-auth'] as $path) {
                if (!isset($doc['paths'][$path])) {
                    throw new RuntimeException('Combined OpenAPI document is missing ' . $path);
                }
            }
            $migrator->rollback();
            $migrator->migrate();
            PHP);
    }

    public function test_auth_first_reuses_its_api_schema(): void
    {
        (new AuthMaker($this->project))->create();
        (new ApiMaker($this->project))->create('AuthenticatedPing');
        self::assertSame([], glob($this->project . '/database/migrations/*_create_api.php'));
        $this->runProject(<<<'PHP'
            use PhpOrbit\Database\Migrator;
            use PhpOrbit\Tests\Support\Requests;

            $app = require __DIR__ . '/app/bootstrap.php';
            $app->container()->get(Migrator::class)->migrate();
            if ($app->handle(Requests::get('/api/v1/authenticated-ping'))->status->value !== 401) {
                throw new RuntimeException('Protected generated route was not loaded.');
            }
            PHP);
    }

    public function test_cli_and_admin_route_lists_include_generated_endpoints(): void
    {
        (new ApiMaker($this->project))->create('ListedEndpoint', public: true);
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->project . '/orbit');
        exec($command . ' routes 2>&1', $output, $status);
        self::assertSame(0, $status, implode("\n", $output));
        self::assertStringContainsString('/api/v1/listed-endpoint', implode("\n", $output));
        $this->runProject(<<<'PHP'
            use PhpOrbit\Admin\AdminApplication;
            use PhpOrbit\Admin\ProjectPaths;

            $routes = AdminApplication::projectRoutes(new ProjectPaths(__DIR__), false);
            foreach ($routes as $route) {
                if ($route->pattern === '/api/v1/listed-endpoint') {
                    exit(0);
                }
            }
            throw new RuntimeException('Admin route list omitted a generated endpoint.');
            PHP);
    }

    public function test_existing_targets_are_refused_before_any_file_is_written(): void
    {
        mkdir($this->project . '/app/api/v1', 0o755, true);
        file_put_contents($this->project . '/app/api/v1/ping.php', 'existing');
        try {
            (new ApiMaker($this->project))->create('Ping');
            self::fail('Existing declarations must never be replaced.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('Refusing to overwrite', $error->getMessage());
        }
        self::assertFileDoesNotExist($this->project . '/app/src/Controllers/Api/V1/PingController.php');
        self::assertFileDoesNotExist($this->project . '/public/assets/swagger/swagger-ui-bundle.js');
        self::assertSame('existing', file_get_contents($this->project . '/app/api/v1/ping.php'));
    }

    public function test_invalid_names_versions_methods_and_symlinks_are_refused(): void
    {
        $maker = new ApiMaker($this->project);
        foreach (['../Outside', 'lowercase', 'Admin/Match', 'Controller'] as $name) {
            try {
                $maker->create($name);
                self::fail('Invalid API name accepted.');
            } catch (InvalidArgumentException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
        foreach ([[Method::Get, 0], [Method::Get, 1000], [Method::Head, 1]] as [$method, $version]) {
            try {
                $maker->create('Ping', $method, $version);
                self::fail('Invalid API options accepted.');
            } catch (InvalidArgumentException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
        symlink($this->project . '/storage', $this->project . '/app/api');
        $this->expectException(RuntimeException::class);
        $maker->create('Ping');
    }

    public function test_scaffolded_cli_generates_without_booting_and_validates_options(): void
    {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->project . '/orbit');
        exec($command . ' make:api Health --method=post --public --version=2 2>&1', $output, $status);
        self::assertSame(0, $status, implode("\n", $output));
        self::assertStringContainsString('POST /api/v2/health', implode("\n", $output));
        self::assertFileDoesNotExist($this->project . '/storage/app.sqlite');
        foreach (['', 'Other --unknown', 'Other --version=bad', 'Other --method=OPTIONS', 'One Two', 'Other --public=false'] as $args) {
            exec($command . ' make:api ' . $args . ' 2>&1', $rejected, $exit);
            self::assertSame(1, $exit, implode("\n", $rejected));
        }
    }

    private function runProject(string $code): void
    {
        file_put_contents($this->project . '/verify.php', "<?php\ndeclare(strict_types=1);\nrequire __DIR__ . '/vendor/autoload.php';\n" . $code);
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->project . '/verify.php') . ' 2>&1', $output, $status);
        self::assertSame(0, $status, implode("\n", $output));
    }
}
