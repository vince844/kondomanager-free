<?php

namespace Database\Factories;

use App\Models\Anagrafica;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AnagraficaFactory extends Factory
{
    protected $model = Anagrafica::class;
    
    // Track unique values within this factory instance
    protected static $usedCodiceFiscale = [];
    protected static $usedDocumentNumbers = [];
    protected static $usedEmails = [];

    public function definition(): array
    {
        return [
            'user_id' => User::inRandomOrder()->value('id'),
            'nome' => $this->faker->firstName() . ' ' . $this->faker->lastName(),
            'indirizzo' => $this->faker->streetAddress() . ', ' . $this->faker->city(),
            'email' => $this->generateUniqueEmail(),
            'email_secondaria' => $this->faker->optional(70)->safeEmail(),
            'pec' => $this->faker->optional(50)->safeEmail(),
            'codice_fiscale' => $this->generateCodiceFiscale(),
            'tipologia_documento' => $this->faker->randomElement(['passport', 'id_card']),
            'numero_documento' => $this->generateDocumentNumber(),
            'scadenza_documento' => $this->faker->dateTimeBetween('now', '+10 years'),
            'luogo_nascita' => $this->faker->city(),
            'data_nascita' => $this->faker->dateTimeBetween('-70 years', '-18 years'),
            'telefono' => $this->faker->phoneNumber(),
            'cellulare' => $this->faker->phoneNumber(),
            'note' => $this->faker->optional(30)->sentence(),
        ];
    }

    /**
     * Generate a valid Italian codice fiscale
     */
    protected function generateCodiceFiscale(): string
    {
        do {
            $cf = Str::upper(
                Str::random(6) . // First 6 consonants of last name
                mt_rand(10, 99) . // Birth year (last two digits)
                Str::upper(Str::random(1)) . // Birth month letter
                mt_rand(10, 99) . // Birth day (with +40 for female)
                Str::upper(Str::random(1)) . // Town code
                mt_rand(100, 999) . // Control characters
                Str::upper(Str::random(1))
            );
        } while (in_array($cf, self::$usedCodiceFiscale));

        self::$usedCodiceFiscale[] = $cf;
        return $cf;
    }

    /**
     * Generate a unique document number
     */
    protected function generateDocumentNumber(): string
    {
        do {
            $docNumber = 'DOC-' . mt_rand(10000000, 99999999);
        } while (in_array($docNumber, self::$usedDocumentNumbers));

        self::$usedDocumentNumbers[] = $docNumber;
        return $docNumber;
    }

    /**
     * Generate a unique email
     */
    protected function generateUniqueEmail(): string
    {
        do {
            $email = Str::lower(
                $this->faker->firstName() . '.' . 
                $this->faker->lastName() . 
                mt_rand(100, 999) . 
                '@' . $this->faker->safeEmailDomain()
            );
        } while (in_array($email, self::$usedEmails));

        self::$usedEmails[] = $email;
        return $email;
    }

    /**
     * Reset the factory's unique trackers
     */
    public static function resetUniqueTrackers(): void
    {
        self::$usedCodiceFiscale = [];
        self::$usedDocumentNumbers = [];
        self::$usedEmails = [];
    }
}