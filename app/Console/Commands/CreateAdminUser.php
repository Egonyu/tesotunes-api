<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Artist;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateAdminUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:create 
                            {--email= : Admin email address}
                            {--name= : Admin name}
                            {--password= : Admin password}
                            {--role=admin : User role (super_admin, admin, moderator, finance)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new admin user interactively or with options';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('═══════════════════════════════════════════');
        $this->info('        CREATE NEW ADMIN USER');
        $this->info('═══════════════════════════════════════════');
        $this->newLine();

        // Get or ask for email
        $email = $this->option('email') ?: $this->ask('Admin email address', 'admin@lineone.com');

        // Validate email
        $validator = Validator::make(['email' => $email], [
            'email' => 'required|email|unique:users,email',
        ]);

        if ($validator->fails()) {
            $this->error('❌ ' . $validator->errors()->first('email'));
            
            // Ask if they want to update existing user
            if ($this->confirm('User with this email exists. Update password?', true)) {
                return $this->updateExistingUser($email);
            }
            
            return 1;
        }

        // Get or ask for name
        $name = $this->option('name') ?: $this->ask('Admin name', 'Administrator');

        // Get or ask for password
        $password = $this->option('password') ?: $this->secret('Admin password (min 8 characters)');
        
        if (!$password || strlen($password) < 8) {
            $password = 'admin123';
            $this->warn('⚠️  Using default password: admin123');
        }

        // Get or ask for role
        $role = $this->option('role');
        if (!in_array($role, ['super_admin', 'admin', 'moderator', 'finance'])) {
            $role = $this->choice(
                'Select role',
                ['super_admin', 'admin', 'moderator', 'finance'],
                1
            );
        }

        // Generate username from name (slugified) with uniqueness check
        $baseUsername = \Illuminate\Support\Str::slug($name, '_');
        $username = $baseUsername;
        $counter = 1;
        while (User::where('username', $username)->exists()) {
            $username = $baseUsername . '_' . $counter;
            $counter++;
        }

        // Create user
        try {
            $user = User::create([
                'name' => $name,
                'username' => $username,
                'email' => $email,
                'password' => Hash::make($password),
                'role' => $role,
                'status' => 'active',
                'is_active' => true,
                'email_verified_at' => now(),
                'phone_verified_at' => now(),
                'verified_at' => now(),
            ]);

            $this->newLine();
            $this->info('✓ Admin user created successfully!');
            $this->newLine();
            
            $this->table(
                ['Field', 'Value'],
                [
                    ['Name', $user->name],
                    ['Username', $user->username],
                    ['Email', $user->email],
                    ['Password', $password],
                    ['Role', $user->role],
                    ['Status', $user->status],
                    ['Created', $user->created_at->format('Y-m-d H:i:s')],
                ]
            );

            $this->newLine();
            $this->warn('⚠️  Please save these credentials securely!');
            $this->info('Login URL: ' . config('app.url') . '/admin/login');
            $this->newLine();

            return 0;

        } catch (\Exception $e) {
            $this->error('❌ Error creating admin user: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * Update existing user's password and role
     */
    private function updateExistingUser(string $email)
    {
        $user = User::where('email', $email)->first();

        if (!$user) {
            $this->error('❌ User not found');
            return 1;
        }

        $this->info('Updating user: ' . $user->name . ' (' . $user->email . ')');
        $this->newLine();

        // Ask for new password
        $newPassword = $this->secret('New password (leave empty to keep current)');
        
        if ($newPassword && strlen($newPassword) >= 8) {
            $user->password = Hash::make($newPassword);
            $this->info('✓ Password updated');
        } else {
            $newPassword = null;
            $this->warn('⚠️  Password not changed');
        }

        // Ask to update role
        if ($this->confirm('Update role? (currently: ' . $user->role . ')', false)) {
            $newRole = $this->choice(
                'Select new role',
                ['super_admin', 'admin', 'moderator', 'finance'],
                array_search($user->role, ['super_admin', 'admin', 'moderator', 'finance'])
            );
            $user->role = $newRole;
            $this->info('✓ Role updated to: ' . $newRole);
        }

        // Make sure user is active
        $user->status = 'active';
        $user->is_active = true;
        $user->email_verified_at = $user->email_verified_at ?? now();

        $user->save();

        $this->newLine();
        $this->info('✓ User updated successfully!');
        $this->newLine();

        $this->table(
            ['Field', 'Value'],
            [
                ['Name', $user->name],
                ['Email', $user->email],
                ['Password', $newPassword ? $newPassword : '(unchanged)'],
                ['Role', $user->role],
                ['Status', $user->status],
            ]
        );

        $this->newLine();

        return 0;
    }
}

