<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class MakeService extends Command
{
    protected $signature = 'make:service {name}';
    protected $description = 'Create a new service class';

    public function handle()
    {
        $name = $this->argument('name');
        $serviceName = str_ends_with($name, 'Service') ? $name : $name . 'Service';
        
        $path = app_path("Services/{$serviceName}.php");

        // Create Services directory if it doesn't exist
        if (!File::exists(app_path('Services'))) {
            File::makeDirectory(app_path('Services'), 0755, true);
        }

        // Check if file already exists
        if (File::exists($path)) {
            $this->error("Service already exists!");
            return 1;
        }

        // Service template
        $stub = <<<PHP
<?php

namespace App\Services;

class {$serviceName}
{
    /**
     * Create a new service instance.
     */
    public function __construct()
    {
        //
    }

    // Add your service methods here
}
PHP;

        File::put($path, $stub);

        $this->info("Service [{$path}] created successfully.");
        return 0;
    }
}