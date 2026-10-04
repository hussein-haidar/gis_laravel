<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Location;
use App\Services\Ai\GroqService;
use App\Services\Ai\PetaContextService;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    /**
     * Jawab pertanyaan pengguna tentang data peta / perencanaan perjalanan
     * memakai Groq, dengan konteks lokasi yang diambil dari database.
     */
    public function ask(Request $request, GroqService $groq, PetaContextService $peta)
    {
        if (! $groq->enabled()) {
            return response()->json([
                'reply' => __('chat.unavailable'),
                'sources' => [],
            ]);
        }

        $validated = $request->validate(
            [
                'message' => ['required', 'string', 'max:1000'],
                'lat' => ['nullable', 'numeric', 'between:-90,90'],
                'lng' => ['nullable', 'numeric', 'between:-180,180'],
                'history' => ['nullable', 'array', 'max:8'],
                'history.*.role' => ['required', 'in:user,assistant'],
                'history.*.content' => ['required', 'string', 'max:2000'],
            ],
            [
                'message.required' => __('chat.validation_message_required'),
                'message.max' => __('chat.validation_message_max'),
                'lat.between' => __('chat.validation_lat'),
                'lng.between' => __('chat.validation_lng'),
            ],
            [
                'message' => __('chat.field_message'),
                'lat' => __('chat.field_lat'),
                'lng' => __('chat.field_lng'),
                'history' => __('chat.field_history'),
            ]
        );

        $message = trim($validated['message']);
        $lat = $request->filled('lat') ? (float) $request->input('lat') : null;
        $lng = $request->filled('lng') ? (float) $request->input('lng') : null;

        $context = $peta->buildContext($message, $lat, $lng);

        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($context, $lat, $lng)],
        ];

        foreach ($request->input('history', []) as $turn) {
            $messages[] = [
                'role' => $turn['role'],
                'content' => $turn['content'],
            ];
        }

        $messages[] = ['role' => 'user', 'content' => $message];

        $reply = $groq->chat($messages);

        if ($reply === null) {
            return response()->json([
                'reply' => __('chat.failed'),
                'sources' => [],
            ]);
        }

        return response()->json([
            'reply' => $reply,
            'sources' => $this->relatedLocations($message, $lat, $lng, $peta),
        ]);
    }

    protected function systemPrompt(string $context, ?float $lat, ?float $lng): string
    {
        $locale = app()->getLocale();
        $bahasa = $locale === 'id'
            ? 'Bahasa Indonesia'
            : 'English';

        $posisi = $lat !== null && $lng !== null
            ? sprintf('Posisi user saat ini: %.4f, %.4f.', $lat, $lng)
            : 'Posisi user belum diketahui (belum memberi izin geolokasi).';

        return <<<PROMPT
Kamu adalah asisten perjalanan untuk aplikasi peta interaktif Indonesia.
Jawab dalam {$bahasa}. Nada bicara ramah, ringkas, dan praktis.

Aturan penting:
1. Jawab HANYA berdasarkan KONTEKS DATA PETA di bawah. Jangan mengarang nama tempat, koordinat, harga, atau jam buka yang tidak ada di sana.
2. Jika data tidak cukup, katakan terus terang apa yang tidak tersedia, lalu suggest apa yang bisa dicari berikutnya.
3. Setiap kali menyebut nama tempat, sertakan kategori dan wilayahnya agar pengguna bisa mencocokkan dengan peta.
4. Untuk pertanyaan "terdekat" atau "di dekat saya", gunakan data lokasi terdekat yang tersedia beserta jaraknya dalam km.
5. Saat menyusun rencana perjalanan, urutkan nama tempat dari yang paling dekat agar mudah dikunjungi di lapangan.
6. Jawab maksimal 6 kalimat pendek. Hindari bullet panjang kecuali memang diminta.
7. {$posisi}

KONTEKS DATA PETA:
{$context}
PROMPT;
    }

    /**
     * Daftar tempat yang relevan untuk ditampilkan sebagai kartu tautan.
     * Diurutkan sesuai urutan data yang diberikan ke AI: lokasi terdekat dulu,
     * lalu hasil pencocokan kata kunci, supaya kartu cocok dengan isi jawaban.
     */
    protected function relatedLocations(
        string $message,
        ?float $lat,
        ?float $lng,
        PetaContextService $peta
    ): array {
        $hasil = [];
        $terpakai = [];

        $tambah = function (Location $loc) use (&$hasil, &$terpakai) {
            if (in_array($loc->id, $terpakai, true) || count($hasil) >= 6) {
                return;
            }

            $terpakai[] = $loc->id;
            $hasil[] = [
                'id' => $loc->id,
                'name' => $loc->name,
                'category' => $loc->category?->name,
                'url' => route('map.show', $loc),
            ];
        };

        // Lokasi terdekat lebih dulu - inilah yang biasanya dipakai AI
        // untuk menjawab pertanyaan "di dekat saya".
        if ($lat !== null && $lng !== null) {
            foreach ($peta->nearbyLocations($lat, $lng, 25, 6) as $loc) {
                $tambah($loc);
            }
        }

        $kataKunci = $peta->keywords($message);

        if (! empty($kataKunci)) {
            $cocok = Location::query()
                ->publiclyVisible()
                ->whereHas('category', fn ($q) => $q->whereIn('name', Category::PLACE_TYPES))
                ->where(function ($q) use ($kataKunci) {
                    foreach ($kataKunci as $kata) {
                        $q->orWhere('name', 'like', "%{$kata}%");
                    }
                })
                ->with('category')
                ->orderBy('name')
                ->limit(10)
                ->get();

            foreach ($cocok as $loc) {
                $tambah($loc);
            }
        }

        return $hasil;
    }
}
