<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
class RoleAndPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
         // Crear roles
         $webuser = Role::create(['name' => 'webuser']);
         $admin = Role::create(['name' => 'admin']);

         //permisos
         //client view, admin view
         Permission::create(['name' => 'client.view']);
         Permission::create(['name' => 'admin.view']);

         // Asignar permisos a roles
         //Webuser
         $webuser->givePermissionTo('client.view');

         //Admin
         $admin->givePermissionTo('admin.view');
         $admin->givePermissionTo('client.view');
        // $admin->givePermissionTo('view-logs'); // Asegúrate de agregar esto
 
    }
}
