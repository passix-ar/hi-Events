<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PassixDemoSeeder extends Seeder
{
    // Unsplash images by category (free, no auth needed via source.unsplash.com)
    private array $images = [
        'FESTIVAL'  => 'https://images.unsplash.com/photo-1540039155733-5bb30b4f5fde?w=1200&h=600&fit=crop',
        'MUSIC'     => 'https://images.unsplash.com/photo-1493225457124-a3eb161ffa5f?w=1200&h=600&fit=crop',
        'TECH'      => 'https://images.unsplash.com/photo-1540575467063-178a50c2df87?w=1200&h=600&fit=crop',
        'NIGHTLIFE' => 'https://images.unsplash.com/photo-1516450360452-9312f5e86fc7?w=1200&h=600&fit=crop',
        'SPORTS'    => 'https://images.unsplash.com/photo-1461896836934-ffe607ba8211?w=1200&h=600&fit=crop',
        'FOOD_DRINK'=> 'https://images.unsplash.com/photo-1414235077428-338989a2e8c0?w=1200&h=600&fit=crop',
        'SOCIAL'    => 'https://images.unsplash.com/photo-1528605248644-14dd04022da1?w=1200&h=600&fit=crop',
        'EDUCATION' => 'https://images.unsplash.com/photo-1524178232363-1fb2b075b655?w=1200&h=600&fit=crop',
    ];

    private array $events = [
        // Organizer 1 (account 1, user 1)
        [
            'organizer_id' => 1, 'account_id' => 1, 'user_id' => 1,
            'title'    => 'Lollapalooza Argentina 2027',
            'category' => 'FESTIVAL',
            'description' => '<p>El festival de música más grande de Sudamérica vuelve al Hipódromo de San Isidro. Tres días de música, arte y cultura con los mejores artistas internacionales.</p>',
            'start_date'  => '2027-03-19 12:00:00',
            'end_date'    => '2027-03-21 23:59:00',
            'location'    => 'Hipódromo de San Isidro, Buenos Aires',
            'location_details' => ['venue_name' => 'Hipódromo de San Isidro', 'city' => 'Buenos Aires', 'country' => 'AR', 'address_line_1' => 'Av. Márquez 504'],
            'products' => [
                ['name' => 'Entrada General 1 Día', 'price' => 85000, 'qty' => 5000],
                ['name' => 'Abono 3 Días', 'price' => 220000, 'qty' => 2000],
                ['name' => 'VIP Gold', 'price' => 450000, 'qty' => 500],
                ['name' => 'Early Bird', 'price' => 65000, 'qty' => 1000],
            ],
        ],
        [
            'organizer_id' => 1, 'account_id' => 1, 'user_id' => 1,
            'title'    => 'Airbag — Estadio Vélez',
            'category' => 'MUSIC',
            'description' => '<p>La banda más importante del rock argentino de los últimos años llega al José Amalfitani. Una noche histórica que no te podés perder.</p>',
            'start_date'  => '2027-05-15 21:00:00',
            'end_date'    => '2027-05-15 23:30:00',
            'location'    => 'Estadio José Amalfitani, Buenos Aires',
            'location_details' => ['venue_name' => 'Estadio José Amalfitani', 'city' => 'Buenos Aires', 'country' => 'AR', 'address_line_1' => 'Av. Juan B. Justo 9200'],
            'products' => [
                ['name' => 'Campo',       'price' => 48000, 'qty' => 8000],
                ['name' => 'Platea Baja', 'price' => 68000, 'qty' => 4000],
                ['name' => 'Platea Alta', 'price' => 38000, 'qty' => 6000],
                ['name' => 'Palco VIP',   'price' => 130000, 'qty' => 300],
            ],
        ],
        [
            'organizer_id' => 1, 'account_id' => 1, 'user_id' => 1,
            'title'    => 'Creamfields Buenos Aires 2027',
            'category' => 'NIGHTLIFE',
            'description' => '<p>El festival de música electrónica más grande del mundo aterriza en Buenos Aires. Dos días de techno, house y EDM en la Costanera Sur.</p>',
            'start_date'  => '2027-11-27 16:00:00',
            'end_date'    => '2027-11-28 08:00:00',
            'location'    => 'Costanera Sur, Buenos Aires',
            'location_details' => ['venue_name' => 'Costanera Sur', 'city' => 'Buenos Aires', 'country' => 'AR'],
            'products' => [
                ['name' => 'General 2 Días', 'price' => 95000, 'qty' => 10000],
                ['name' => 'VIP 2 Días',     'price' => 220000, 'qty' => 1000],
                ['name' => 'Early Bird',      'price' => 72000,  'qty' => 2000],
            ],
        ],
        [
            'organizer_id' => 1, 'account_id' => 1, 'user_id' => 1,
            'title'    => 'Festival de Jazz de Buenos Aires',
            'category' => 'MUSIC',
            'description' => '<p>Cinco días de jazz en vivo en el Centro Cultural Kirchner. Artistas nacionales e internacionales en el escenario más emblemático de Buenos Aires.</p>',
            'start_date'  => '2027-09-10 19:00:00',
            'end_date'    => '2027-09-14 23:00:00',
            'location'    => 'Centro Cultural Kirchner, Buenos Aires',
            'location_details' => ['venue_name' => 'Centro Cultural Kirchner', 'city' => 'Buenos Aires', 'country' => 'AR', 'address_line_1' => 'Sarmiento 151'],
            'products' => [
                ['name' => 'Abono Festival',     'price' => 42000, 'qty' => 3000],
                ['name' => 'Función Individual', 'price' => 11000, 'qty' => 800],
                ['name' => 'Entrada Libre',      'price' => 0, 'qty' => 500, 'type' => 'FREE'],
            ],
        ],

        // Organizer 2 (account 2, user 2)
        [
            'organizer_id' => 2, 'account_id' => 2, 'user_id' => 2,
            'title'    => 'TEDxBuenosAires 2027',
            'category' => 'EDUCATION',
            'description' => '<p>Ideas worth spreading. La edición más grande de TEDx en Argentina con speakers locales e internacionales que van a cambiar tu manera de ver el mundo.</p>',
            'start_date'  => '2027-08-22 09:00:00',
            'end_date'    => '2027-08-22 20:00:00',
            'location'    => 'Teatro Gran Rex, Buenos Aires',
            'location_details' => ['venue_name' => 'Teatro Gran Rex', 'city' => 'Buenos Aires', 'country' => 'AR', 'address_line_1' => 'Av. Corrientes 857'],
            'products' => [
                ['name' => 'Entrada General',    'price' => 28000, 'qty' => 1200],
                ['name' => 'Entrada Estudiante', 'price' => 14000, 'qty' => 300],
            ],
        ],
        [
            'organizer_id' => 2, 'account_id' => 2, 'user_id' => 2,
            'title'    => 'Mercado de Diseño BA 2027',
            'category' => 'SOCIAL',
            'description' => '<p>El encuentro anual de diseñadores, creativos y makers de Argentina. Tres días de expo, charlas y workshops en La Rural.</p>',
            'start_date'  => '2027-11-05 11:00:00',
            'end_date'    => '2027-11-07 20:00:00',
            'location'    => 'La Rural, Buenos Aires',
            'location_details' => ['venue_name' => 'La Rural', 'city' => 'Buenos Aires', 'country' => 'AR', 'address_line_1' => 'Av. Santa Fe 4201'],
            'products' => [
                ['name' => 'Entrada Diaria', 'price' => 9000,  'qty' => 2000],
                ['name' => 'Pase 3 Días',    'price' => 22000, 'qty' => 800],
                ['name' => 'Acceso Libre',   'price' => 0,     'qty' => 500, 'type' => 'FREE'],
            ],
        ],
        [
            'organizer_id' => 2, 'account_id' => 2, 'user_id' => 2,
            'title'    => 'Córdoba Tech Summit 2027',
            'category' => 'TECH',
            'description' => '<p>El evento de tecnología más importante del interior del país. Startups, inversores y desarrolladores reunidos en Córdoba.</p>',
            'start_date'  => '2027-07-18 09:00:00',
            'end_date'    => '2027-07-19 19:00:00',
            'location'    => 'Pabellón Argentina, Córdoba',
            'location_details' => ['venue_name' => 'Pabellón Argentina', 'city' => 'Córdoba', 'country' => 'AR'],
            'products' => [
                ['name' => 'Ticket Early Bird', 'price' => 18000, 'qty' => 500],
                ['name' => 'Ticket General',    'price' => 28000, 'qty' => 1500],
                ['name' => 'Sponsor Pass',      'price' => 85000, 'qty' => 50],
            ],
        ],
        [
            'organizer_id' => 2, 'account_id' => 2, 'user_id' => 2,
            'title'    => 'Copa Argentina de Fútbol — Final 2027',
            'category' => 'SPORTS',
            'description' => '<p>La final más esperada del año. Dos grandes del fútbol argentino se enfrentan por el título en el Estadio Único de La Plata.</p>',
            'start_date'  => '2027-12-12 21:00:00',
            'end_date'    => '2027-12-12 23:00:00',
            'location'    => 'Estadio Único de La Plata',
            'location_details' => ['venue_name' => 'Estadio Único Ciudad de La Plata', 'city' => 'La Plata', 'country' => 'AR'],
            'products' => [
                ['name' => 'Tribuna Popular', 'price' => 35000,  'qty' => 20000],
                ['name' => 'Platea',          'price' => 75000,  'qty' => 10000],
                ['name' => 'Palco Premium',   'price' => 200000, 'qty' => 500],
            ],
        ],
    ];

    public function run(): void
    {
        $this->command->info('🌱 Iniciando seed de Passix Demo...');

        foreach ($this->events as $data) {
            $this->command->info("  → Creando: {$data['title']}");

            // 1. Create event
            $eventId = DB::table('events')->insertGetId([
                'title'            => $data['title'],
                'account_id'       => $data['account_id'],
                'user_id'          => $data['user_id'],
                'organizer_id'     => $data['organizer_id'],
                'category'         => $data['category'],
                'description'      => $data['description'],
                'status'           => 'LIVE',
                'start_date'       => $data['start_date'],
                'end_date'         => $data['end_date'],
                'currency'         => 'ARS',
                'timezone'         => 'America/Argentina/Buenos_Aires',
                'location'         => $data['location'],
                'location_details' => json_encode($data['location_details']),
                'short_id'         => Str::random(8),
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            // 2. Create event settings
            DB::table('event_settings')->insert([
                'event_id'                     => $eventId,
                'support_email'                => "hola@passix.com.ar",
                'homepage_background_color'    => '#0b0b0e',
                'homepage_primary_color'       => '#d6ff3d',
                'homepage_primary_text_color'  => '#0b0b0e',
                'homepage_secondary_color'     => '#131319',
                'homepage_secondary_text_color'=> '#8e8e98',
                'homepage_background_type'     => 'COLOR',
                'homepage_theme_settings'      => json_encode([
                    'mode'            => 'dark',
                    'accent'          => '#d6ff3d',
                    'background'      => '#0b0b0e',
                    'background_type' => 'COLOR',
                    'font_family'     => null,
                ]),
                'order_timeout_in_minutes'     => 15,
                'continue_button_text'         => 'Continuar',
                'attendee_details_collection_method' => 'PER_TICKET',
                'created_at'                   => now(),
                'updated_at'                   => now(),
            ]);

            // 3. Create product category
            $catId = DB::table('product_categories')->insertGetId([
                'name'       => 'Entradas',
                'event_id'   => $eventId,
                'order'      => 0,
                'is_hidden'  => false,
                'no_products_message' => 'No hay entradas disponibles.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 4. Create products + prices
            foreach ($data['products'] as $i => $prod) {
                $type = $prod['type'] ?? 'PAID';
                $productId = DB::table('products')->insertGetId([
                    'title'               => $prod['name'],
                    'event_id'            => $eventId,
                    'product_category_id' => $catId,
                    'type'                => $type,
                    'product_type'        => 'TICKET',
                    'order'               => $i,
                    'is_hidden'           => false,
                    'created_at'          => now(),
                    'updated_at'          => now(),
                ]);

                DB::table('product_prices')->insert([
                    'product_id'               => $productId,
                    'price'                    => $type === 'FREE' ? 0 : $prod['price'],
                    'initial_quantity_available'=> $prod['qty'] ?? 1000,
                    'quantity_available'        => $prod['qty'] ?? 1000,
                    'quantity_sold'            => 0,
                    'is_hidden'                => false,
                    'order'                    => 0,
                    'created_at'               => now(),
                    'updated_at'               => now(),
                ]);
            }

            // 5. Download & upload cover image
            $this->uploadCoverImage($eventId, $data['account_id'], $data['category']);
        }

        $this->command->info('✅ Seed completado. ' . count($this->events) . ' eventos creados.');
    }

    private function uploadCoverImage(int $eventId, int $accountId, string $category): void
    {
        $url = $this->images[$category] ?? $this->images['FESTIVAL'];

        try {
            $response = Http::timeout(15)->get($url);
            if (!$response->successful()) {
                $this->command->warn("    ⚠ No se pudo descargar imagen para evento $eventId");
                return;
            }

            $ext      = 'jpg';
            $filename = Str::random(10) . '.' . $ext;
            $path     = "event_cover/{$filename}";

            Storage::disk('s3-public')->put($path, $response->body(), 'public');

            DB::table('images')->insert([
                'entity_id'   => $eventId,
                'entity_type' => 'HiEvents\\DomainObjects\\EventDomainObject',
                'type'        => 'EVENT_COVER',
                'filename'    => $filename,
                'disk'        => 's3-public',
                'path'        => $path,
                'size'        => strlen($response->body()),
                'mime_type'   => 'image/jpeg',
                'account_id'  => $accountId,
                'width'       => 1200,
                'height'      => 600,
                'avg_colour'  => '#1a1a1a',
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            $this->command->info("    📸 Imagen subida: $path");
        } catch (\Exception $e) {
            $this->command->warn("    ⚠ Error al subir imagen: " . $e->getMessage());
        }
    }
}
