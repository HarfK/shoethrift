<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Loads ~2 years of semi-synthetic thrift sales history (Nov 2024 - Nov 2026) for the
 * ML features (revenue forecast, restock prediction, price recommendation).
 *
 *   php artisan migrate
 *   php artisan db:seed --class=ThriftHistorySeeder
 *
 * What it does:
 *   - KEEPS the 4 test accounts (admin, customer, cod.blocked, tester) and their
 *     addresses / preferences.
 *   - DELETES all dummy products, orders, carts, reviews, returns, interactions,
 *     notifications and the dummy (Faker) customers.
 *   - Loads the CSVs in database/seeders/data/thrift/.
 *   - Re-uses the existing product photos by model name. Pairs that are still
 *     available get their OWN copy of the photo files, so deleting one product
 *     in the admin panel can never remove a photo another product uses.
 *
 * Generated customers can log in with the password: password
 */
class ThriftHistorySeeder extends Seeder
{
    private const KEEP_EMAILS = [
        'admin@thriftstore.test',
        'customer@thriftstore.test',
        'cod.blocked@thriftstore.test',
        'tester@thriftstore.test',
        'unverified@thriftstore.test',
    ];

    /** Storage disk where product photos live (image_path = "products/xxx.jpg"). */
    private const PHOTO_DISK = 'public';

    private const CHUNK = 500;

    private string $dir;

    /** @var array<string,int> CSV user key => users.id */
    private array $userIds = [];

    public function run(): void
    {
        $this->dir = database_path('seeders/data/thrift');

        if (! Schema::hasColumn('products', 'retail_price')) {
            $this->command->error('Column products.retail_price is missing. Run "php artisan migrate" first.');

            return;
        }
        if (! is_file($this->dir.'/products.csv')) {
            $this->command->error("CSV files not found in {$this->dir}");

            return;
        }
        if (! $this->command->confirm(
            'This DELETES all products, orders, carts, reviews, returns and dummy customers, then loads the thrift history. Continue?',
            true
        )) {
            return;
        }

        $photoSets = $this->capturePhotoSets();
        $this->command->info('Photo sets found for '.count($photoSets).' models.');

        $this->wipeDummyData();
        $this->seedUsers();
        $this->seedProducts();
        $this->seedProductImages($photoSets);
        $this->seedOrders();
        $this->seedOrderItems();
        $this->seedInteractions();

        $this->command->info(sprintf(
            'Done: %d customers, %d products, %d orders, %d order items, %d interactions.',
            count($this->userIds),
            DB::table('products')->count(),
            DB::table('orders')->count(),
            DB::table('order_items')->count(),
            DB::table('user_interactions')->count(),
        ));
    }

    // -----------------------------------------------------------------------------
    // 1. Remember which photos belong to which shoe model before products are wiped
    // -----------------------------------------------------------------------------
    /** @return array<string, array<int, array<int, array{path:string, is_primary:int}>>> */
    private function capturePhotoSets(): array
    {
        $rows = DB::table('product_images')
            ->join('products', 'products.id', '=', 'product_images.product_id')
            ->orderBy('product_images.product_id')
            ->orderByDesc('product_images.is_primary')
            ->orderBy('product_images.id')
            ->get(['products.name', 'product_images.product_id', 'product_images.image_path', 'product_images.is_primary']);

        $byProduct = [];
        foreach ($rows as $r) {
            $byProduct[$r->name][$r->product_id][] = ['path' => $r->image_path, 'is_primary' => (int) $r->is_primary];
        }

        $sets = [];
        foreach ($byProduct as $name => $products) {
            $sets[$name] = array_values($products);
        }

        return $sets;
    }

    // -----------------------------------------------------------------------------
    // 2. Remove dummy data, keep the 4 test accounts
    // -----------------------------------------------------------------------------
    private function wipeDummyData(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'review_media', 'reviews',
            'return_request_photos', 'return_request_items', 'return_requests',
            'order_items', 'orders',
            'cart_items', 'cart_releases', 'product_watches',
            'user_interactions', 'notifications',
            'product_images', 'products',
        ] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->truncate();
            }
        }

        $removeIds = DB::table('users')->whereNotIn('email', self::KEEP_EMAILS)->pluck('id');
        if ($removeIds->isNotEmpty()) {
            DB::table('addresses')->whereIn('user_id', $removeIds)->delete();
            DB::table('carts')->whereIn('user_id', $removeIds)->delete();
            DB::table('user_preferences')->whereIn('user_id', $removeIds)->delete();
            DB::table('contact_messages')->whereIn('user_id', $removeIds)->update(['user_id' => null]);
            DB::table('users')->whereIn('id', $removeIds)->delete();
        }

        Schema::enableForeignKeyConstraints();
        $this->command->info('Removed dummy data ('.$removeIds->count().' dummy customers).');
    }

    // -----------------------------------------------------------------------------
    // 3. Customers, addresses, preferences
    // -----------------------------------------------------------------------------
    private function seedUsers(): void
    {
        foreach (self::KEEP_EMAILS as $email) {
            $id = DB::table('users')->where('email', $email)->value('id');
            if ($id) {
                $this->userIds['KEEP:'.$email] = (int) $id;
            }
        }

        $password = Hash::make('password');
        foreach ($this->readCsv('users.csv') as $u) {
            $this->userIds[$u['key']] = (int) DB::table('users')->insertGetId([
                'name' => $u['name'],
                'email' => $u['email'],
                'role' => 'customer',
                'phone' => $u['phone'],
                'phone_normalized' => $u['phone_normalized'],
                'email_verified_at' => $u['email_verified_at'],
                // generated customers count as phone-verified too, like the other seeded accounts
                'phone_verified_at' => $u['email_verified_at'],
                'password' => $password,
                'created_at' => $u['created_at'],
                'updated_at' => $u['created_at'],
            ]);
        }

        $this->insertChunked('addresses', $this->readCsv('addresses.csv'), fn ($a) => [
            'user_id' => $this->userIds[$a['user_key']],
            'label' => $this->nullable($a['label']),
            'recipient_name' => $a['recipient_name'],
            'phone' => $a['phone'],
            'line1' => $a['line1'],
            'line2' => $this->nullable($a['line2']),
            'postcode' => $a['postcode'],
            'city' => $a['city'],
            'state' => $a['state'],
            'is_default' => (int) $a['is_default'],
            'created_at' => $a['created_at'],
            'updated_at' => $a['created_at'],
        ]);

        $this->insertChunked('user_preferences', $this->readCsv('user_preferences.csv'), fn ($p) => [
            'user_id' => $this->userIds[$p['user_key']],
            'shoe_size' => $p['shoe_size'],
            'preferred_brands' => $p['preferred_brands'],
            'min_price' => $p['min_price'],
            'max_price' => $p['max_price'],
            'created_at' => $p['created_at'],
            'updated_at' => $p['created_at'],
        ]);
    }

    // -----------------------------------------------------------------------------
    // 4. Products and photos
    // -----------------------------------------------------------------------------
    private function seedProducts(): void
    {
        $this->insertChunked('products', $this->readCsv('products.csv'), fn ($p) => [
            'id' => (int) $p['id'],
            'category_id' => (int) $p['category_id'],
            'name' => $p['name'],
            'slug' => $p['slug'],
            'brand' => $p['brand'],
            'description' => $p['description'],
            'price' => $p['price'],
            'retail_price' => $p['retail_price'],
            'condition' => $p['condition'],
            'size' => $p['size'],
            'color' => $this->nullable($p['color']),
            'material' => $this->nullable($p['material']),
            'insole_length_cm' => $this->nullable($p['insole_length_cm']),
            'sole_condition' => $this->nullable($p['sole_condition']),
            'box_included' => (int) $p['box_included'],
            'wear_notes' => $this->nullable($p['wear_notes']),
            'authenticity_notes' => $this->nullable($p['authenticity_notes']),
            'stock' => (int) $p['stock'],
            'status' => $p['status'],
            'created_at' => $p['created_at'],
            'updated_at' => $p['updated_at'],
        ]);
    }

    private function seedProductImages(array $photoSets): void
    {
        $disk = Storage::disk(self::PHOTO_DISK);
        $copied = 0;
        $missing = [];

        $rows = (function () use ($photoSets, $disk, &$copied, &$missing) {
            foreach ($this->readCsv('products.csv') as $p) {
                $sets = $photoSets[$p['name']] ?? null;
                if (! $sets) {
                    $missing[$p['name']] = true;

                    continue;
                }
                $set = $sets[crc32($p['slug']) % count($sets)];
                foreach ($set as $photo) {
                    $path = $photo['path'];
                    // available pairs get their own file, so an admin delete is always safe
                    if ($p['status'] !== 'sold' && $disk->exists($path)) {
                        $newPath = 'products/'.Str::uuid().'.'.pathinfo($path, PATHINFO_EXTENSION);
                        $disk->copy($path, $newPath);
                        $path = $newPath;
                        $copied++;
                    }
                    yield [
                        'product_id' => (int) $p['id'],
                        'image_path' => $path,
                        'is_primary' => $photo['is_primary'],
                        'created_at' => $p['created_at'],
                        'updated_at' => $p['created_at'],
                    ];
                }
            }
        })();

        $this->insertChunked('product_images', $rows, fn ($r) => $r);
        $this->command->info("Product photos linked ({$copied} files copied for available pairs).");
        if ($missing) {
            $this->command->warn('No photos found for: '.implode(', ', array_keys($missing)));
        }
    }

    // -----------------------------------------------------------------------------
    // 5. Orders, order items, interactions
    // -----------------------------------------------------------------------------
    private function seedOrders(): void
    {
        $this->insertChunked('orders', $this->readCsv('orders.csv'), fn ($o) => [
            'id' => (int) $o['id'],
            'user_id' => $this->userIds[$o['user_key']],
            'order_number' => $o['order_number'],
            'total' => $o['total'],
            'refunded_amount' => $o['refunded_amount'],
            'shipping_fee' => $o['shipping_fee'],
            'status' => $o['status'],
            'payment_method' => $o['payment_method'],
            'gateway_reference' => $this->nullable($o['gateway_reference']),
            'gateway_payment_id' => $this->nullable($o['gateway_payment_id']),
            'payment_url' => null,
            'payment_expires_at' => $this->nullable($o['payment_expires_at']),
            'paid_at' => $this->nullable($o['paid_at']),
            'delivered_at' => $this->nullable($o['delivered_at']),
            'delivery_failed_at' => $this->nullable($o['delivery_failed_at']),
            'shipping_name' => $o['shipping_name'],
            'shipping_phone' => $o['shipping_phone'],
            'shipping_address' => $o['shipping_address'],
            'created_at' => $o['created_at'],
            'updated_at' => $o['updated_at'],
        ]);
    }

    private function seedOrderItems(): void
    {
        $this->insertChunked('order_items', $this->readCsv('order_items.csv'), fn ($i) => [
            'id' => (int) $i['id'],
            'order_id' => (int) $i['order_id'],
            'product_id' => (int) $i['product_id'],
            'quantity' => (int) $i['quantity'],
            'price' => $i['price'],
            'created_at' => $i['created_at'],
            'updated_at' => $i['updated_at'],
        ]);
    }

    private function seedInteractions(): void
    {
        $this->insertChunked('user_interactions', $this->readCsv('user_interactions.csv'), fn ($r) => [
            'user_id' => $this->userIds[$r['user_key']],
            'product_id' => (int) $r['product_id'],
            'type' => $r['type'],
            'source' => $this->nullable($r['source']),
            'created_at' => $r['created_at'],
        ]);
    }

    // -----------------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------------
    /** @return \Generator<int, array<string,string>> */
    private function readCsv(string $file): \Generator
    {
        $h = fopen($this->dir.'/'.$file, 'r');
        $header = fgetcsv($h, null, ',', '"', '');
        while (($row = fgetcsv($h, null, ',', '"', '')) !== false) {
            if ($row === [null]) {
                continue;
            }
            yield array_combine($header, $row);
        }
        fclose($h);
    }

    private function insertChunked(string $table, iterable $rows, callable $map): void
    {
        $batch = [];
        $n = 0;
        foreach ($rows as $row) {
            $batch[] = $map($row);
            if (count($batch) >= self::CHUNK) {
                DB::table($table)->insert($batch);
                $n += count($batch);
                $batch = [];
            }
        }
        if ($batch) {
            DB::table($table)->insert($batch);
            $n += count($batch);
        }
        $this->command->line("  {$table}: {$n} rows");
    }

    private function nullable(?string $v): ?string
    {
        return ($v === null || $v === '') ? null : $v;
    }
}
