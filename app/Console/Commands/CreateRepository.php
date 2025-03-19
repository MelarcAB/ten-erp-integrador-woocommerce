<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class CreateRepository extends Command
{
    protected $signature = 'make:repository {name} {--base}';
    protected $description = 'Create a repository and its interface, and register it in the Service Provider';

    public function handle()
    {
        $name = $this->argument('name');
        $useBase = $this->option('base');

        $this->createInterface($name);
        $this->createRepository($name, $useBase);
        $this->registerInServiceProvider($name);

        $this->info('Repository, Interface, and Service Provider binding created successfully.');
    }


    /**
     * Summary of createInterface
     * Create the repository interface in the app/Repositories/Contracts directory
     * @param mixed $name
     * @return void
     */
    protected function createInterface($name)
    {
        $interfacePath = app_path("Repositories/Contracts/{$name}RepositoryInterface.php");

        if (File::exists($interfacePath)) {
            $this->error("Interface {$name}RepositoryInterface already exists!");
            return;
        }

        $interfaceContent = "<?php

namespace App\Repositories\Contracts;

interface {$name}RepositoryInterface extends BaseModelRepositoryInterface
{
    // Add model-specific methods here
}";

        File::ensureDirectoryExists(app_path('Repositories/Contracts'));
        File::put($interfacePath, $interfaceContent);

        $this->info("Interface {$name}RepositoryInterface created successfully.");
    }


    /**
     * Summary of createRepository
     * Create the repository class in the app/Repositories directory
     * @param mixed $name
     * @param mixed $useBase
     * @return void
     */
    protected function createRepository($name, $useBase)
    {
        $repositoryPath = app_path("Repositories/{$name}Repository.php");

        if (File::exists($repositoryPath)) {
            $this->error("Repository {$name}Repository already exists!");
            return;
        }

        $baseClass = $useBase ? "extends BaseModelRepository implements {$name}RepositoryInterface" : "implements {$name}RepositoryInterface";

        $repositoryContent = "<?php

namespace App\Repositories;

use App\Repositories\Contracts\\{$name}RepositoryInterface;
use Illuminate\Database\Eloquent\Model;
use App\Models\\{$name};

class {$name}Repository $baseClass
{
    " . ($useBase ? "public function __construct({$name} \$model)
    {
        parent::__construct(\$model);
    }" : "") . "

    // Add model-specific methods here
}";

        File::ensureDirectoryExists(app_path('Repositories'));
        File::put($repositoryPath, $repositoryContent);

        $this->info("Repository {$name}Repository created successfully.");
    }


    /**
     * 
     * Register the repository in the RepositoryServiceProvider
     * @param mixed $name
     * @return void
     */
    protected function registerInServiceProvider($name)
    {
        $serviceProviderPath = app_path('Providers/RepositoryServiceProvider.php');
    
        if (!File::exists($serviceProviderPath)) {
            $this->error('RepositoryServiceProvider does not exist. Please create it first.');
            return;
        }
    
        $binding = "\$this->app->bind(\\App\\Repositories\\Contracts\\{$name}RepositoryInterface::class, \\App\\Repositories\\{$name}Repository::class);";
    
        $serviceProviderContent = File::get($serviceProviderPath);
    
        // Check if the binding already exists
        if (strpos($serviceProviderContent, $binding) !== false) {
            $this->error("Binding for {$name}RepositoryInterface already exists in RepositoryServiceProvider.");
            return;
        }
    
        // Add the binding to the register method
        $pattern = '/public function register\(\): void\n\s*\{\n/';
        if (preg_match($pattern, $serviceProviderContent)) {
            $serviceProviderContent = preg_replace(
                $pattern,
                "$0        $binding\n",
                $serviceProviderContent
            );
            File::put($serviceProviderPath, $serviceProviderContent);
            $this->info("{$name}RepositoryInterface binding added to RepositoryServiceProvider.");
        } else {
            $this->error("Could not find the register method in RepositoryServiceProvider.");
        }
    }
    
}
