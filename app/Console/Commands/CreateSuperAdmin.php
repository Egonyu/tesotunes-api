<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateSuperAdmin extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:make-super-admin 
                            {email : The email address of the user}
                            {--password= : The password for the user}
                            {--name= : The name of the user (if creating new)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create or update a user to super admin role';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $email = $this->argument('email');
        $password = $this->option('password');
        $name = $this->option('name');

        // Validate email
        $validator = Validator::make(['email' => $email], [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            $this->error('Invalid email address.');
            return 1;
        }

        // Find existing user
        $user = User::where('email', $email)->first();

        if ($user) {
            // Update existing user
            $this->info("User found: {$user->name} ({$user->email})");
            
            $updates = [
                'role' => 'super_admin',
                'status' => 'active',
                'email_verified_at' => $user->email_verified_at ?? now(),
            ];

            if ($password) {
                $updates['password'] = Hash::make($password);
                $this->info('Password will be updated.');
            }

            if ($this->confirm('Update this user to super admin?', true)) {
                $user->update($updates);
                
                // Assign role if using role package
                if (method_exists($user, 'syncRoles')) {
                    $user->syncRoles(['super_admin']);
                }

                $this->info('✓ User updated to super admin successfully!');
                $this->newLine();
                $this->table(
                    ['Field', 'Value'],
                    [
                        ['Name', $user->name],
                        ['Email', $user->email],
                        ['Role', $user->role],
                        ['Status', $user->status],
                        ['Password', $password ? 'Updated' : 'Unchanged'],
                    ]
                );

                return 0;
            } else {
                $this->warn('Operation cancelled.');
                return 1;
            }
        } else {
            // Create new user
            if (!$password) {
                $password = $this->secret('Enter password for new user');
                
                if (!$password) {
                    $this->error('Password is required for new users.');
                    return 1;
                }
            }

            if (!$name) {
                $name = $this->ask('Enter name for new user', 'Super Admin');
            }

            $this->info("Creating new super admin user...");

            if ($this->confirm('Create new super admin user?', true)) {
                $user = User::create([
                    'name' => $name,
                    'email' => $email,
                    'password' => Hash::make($password),
                    'role' => 'super_admin',
                    'status' => 'active',
                    'email_verified_at' => now(),
                ]);

                // Assign role if using role package
                if (method_exists($user, 'syncRoles')) {
                    $user->syncRoles(['super_admin']);
                }

                $this->info('✓ Super admin user created successfully!');
                $this->newLine();
                $this->table(
                    ['Field', 'Value'],
                    [
                        ['Name', $user->name],
                        ['Email', $user->email],
                        ['Role', $user->role],
                        ['Status', $user->status],
                        ['Password', '***hidden***'],
                    ]
                );

                return 0;
            } else {
                $this->warn('Operation cancelled.');
                return 1;
            }
        }
    }
}
