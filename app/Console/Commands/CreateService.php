<?php

namespace App\Console\Commands;

use Illuminate\Support\Facades\File;
use Illuminate\Console\Command;

class CreateService extends Command
{
    protected $signature = 'make:service {name} {--singleton}';
    protected $description = 'Create a service and its interface, and register it in the AppServiceProvider';

    public function handle()
    {
        $name = $this->argument('name');

        $this->createInterface($name);
        $this->createService($name);
        $this->registerInServiceProvider($name);

        $this->info('Service, Interface, and Service Provider binding created successfully.');
    }

    /**
     * Create the service interface in the app/Services/Contracts directory.
     *
     * @param string $name
     * @return void
     */
    protected function createInterface(string $name)
    {
        $interfacePath = app_path("Services/Contracts/{$name}ServiceInterface.php");

        if (File::exists($interfacePath)) {
            $this->error("Interface {$name}ServiceInterface already exists!");
            return;
        }

        $interfaceContent = "<?php

namespace App\Services\Contracts;

interface {$name}ServiceInterface
{
    // Define your service methods here
}";

        File::ensureDirectoryExists(app_path('Services/Contracts'));
        File::put($interfacePath, $interfaceContent);

        $this->info("Interface {$name}ServiceInterface created successfully.");
    }

    /**
     * Create the service class in the app/Services directory.
     *
     * @param string $name
     * @return void
     */
    protected function createService(string $name)
    {
        $servicePath = app_path("Services/{$name}Service.php");

        if (File::exists($servicePath)) {
            $this->error("Service {$name}Service already exists!");
            return;
        }

        $serviceContent = "<?php

namespace App\Services;

use App\Services\Contracts\\{$name}ServiceInterface;

class {$name}Service implements {$name}ServiceInterface
{
    // Implement your service methods here
}";

        File::ensureDirectoryExists(app_path('Services'));
        File::put($servicePath, $serviceContent);

        $this->info("Service {$name}Service created successfully.");
    }

    /**
     * Register the service in the AppServiceProvider.
     *
     * @param string $name
     * @return void
     */
    protected function registerInServiceProvider(string $name)
    {
        $serviceProviderPath = app_path('Providers/AppServiceProvider.php');

        if (!File::exists($serviceProviderPath)) {
            $this->error('AppServiceProvider does not exist. Please create it first.');
            return;
        }

        // Selecciona el método de binding según la opción --singleton
        $bindingMethod = $this->option('singleton') ? 'singleton' : 'bind';

        $binding = "\$this->app->{$bindingMethod}(\\App\\Services\\Contracts\\{$name}ServiceInterface::class, \\App\\Services\\{$name}Service::class);";

        $serviceProviderContent = File::get($serviceProviderPath);

        // Comprueba si el binding ya existe
        if (strpos($serviceProviderContent, $binding) !== false) {
            $this->error("Binding for {$name}ServiceInterface already exists in AppServiceProvider.");
            return;
        }

        // Agrega el binding al método register
        $pattern = '/public function register\(\): void\n\s*\{\n/';
        if (preg_match($pattern, $serviceProviderContent)) {
            $serviceProviderContent = preg_replace(
                $pattern,
                "$0        $binding\n",
                $serviceProviderContent
            );
            File::put($serviceProviderPath, $serviceProviderContent);
            $this->info("{$name}ServiceInterface binding added to AppServiceProvider using {$bindingMethod}.");
        } else {
            $this->error("Could not find the register method in AppServiceProvider.");
        }
    }
}
