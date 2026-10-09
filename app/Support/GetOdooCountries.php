<?php

namespace App\Support;

use DateTimeZone;

class GetOdooCountries
{
    /**
     * ISO 3166-1 alpha-2 → Spanish display name (standard list for signup / plan areas).
     *
     * @return array<string, string>
     */
    public static function names(): array
    {
        return [
            'AF' => 'Afganistán',
            'AL' => 'Albania',
            'DE' => 'Alemania',
            'AD' => 'Andorra',
            'AO' => 'Angola',
            'AG' => 'Antigua y Barbuda',
            'SA' => 'Arabia Saudita',
            'DZ' => 'Argelia',
            'AR' => 'Argentina',
            'AM' => 'Armenia',
            'AU' => 'Australia',
            'AT' => 'Austria',
            'AZ' => 'Azerbaiyán',
            'BS' => 'Bahamas',
            'BD' => 'Bangladés',
            'BB' => 'Barbados',
            'BH' => 'Baréin',
            'BE' => 'Bélgica',
            'BZ' => 'Belice',
            'BJ' => 'Benín',
            'BY' => 'Bielorrusia',
            'BO' => 'Bolivia',
            'BA' => 'Bosnia y Herzegovina',
            'BW' => 'Botsuana',
            'BR' => 'Brasil',
            'BN' => 'Brunéi',
            'BG' => 'Bulgaria',
            'BF' => 'Burkina Faso',
            'BI' => 'Burundi',
            'BT' => 'Bután',
            'CV' => 'Cabo Verde',
            'KH' => 'Camboya',
            'CM' => 'Camerún',
            'CA' => 'Canadá',
            'QA' => 'Catar',
            'TD' => 'Chad',
            'CL' => 'Chile',
            'CN' => 'China',
            'CY' => 'Chipre',
            'CO' => 'Colombia',
            'KM' => 'Comoras',
            'CG' => 'Congo',
            'CD' => 'Congo (RDC)',
            'KP' => 'Corea del Norte',
            'KR' => 'Corea del Sur',
            'CR' => 'Costa Rica',
            'CI' => 'Costa de Marfil',
            'HR' => 'Croacia',
            'CU' => 'Cuba',
            'DK' => 'Dinamarca',
            'DM' => 'Dominica',
            'EC' => 'Ecuador',
            'EG' => 'Egipto',
            'SV' => 'El Salvador',
            'AE' => 'Emiratos Árabes Unidos',
            'ER' => 'Eritrea',
            'SK' => 'Eslovaquia',
            'SI' => 'Eslovenia',
            'ES' => 'España',
            'US' => 'Estados Unidos',
            'EE' => 'Estonia',
            'ET' => 'Etiopía',
            'PH' => 'Filipinas',
            'FI' => 'Finlandia',
            'FJ' => 'Fiyi',
            'FR' => 'Francia',
            'GA' => 'Gabón',
            'GM' => 'Gambia',
            'GE' => 'Georgia',
            'GH' => 'Ghana',
            'GD' => 'Granada',
            'GR' => 'Grecia',
            'GT' => 'Guatemala',
            'GN' => 'Guinea',
            'GQ' => 'Guinea Ecuatorial',
            'GW' => 'Guinea-Bisáu',
            'GY' => 'Guyana',
            'HT' => 'Haití',
            'HN' => 'Honduras',
            'HU' => 'Hungría',
            'IN' => 'India',
            'ID' => 'Indonesia',
            'IQ' => 'Irak',
            'IR' => 'Irán',
            'IE' => 'Irlanda',
            'IS' => 'Islandia',
            'MH' => 'Islas Marshall',
            'SB' => 'Islas Salomón',
            'IL' => 'Israel',
            'IT' => 'Italia',
            'JM' => 'Jamaica',
            'JP' => 'Japón',
            'JO' => 'Jordania',
            'KZ' => 'Kazajistán',
            'KE' => 'Kenia',
            'KG' => 'Kirguistán',
            'KI' => 'Kiribati',
            'KW' => 'Kuwait',
            'LA' => 'Laos',
            'LS' => 'Lesoto',
            'LV' => 'Letonia',
            'LB' => 'Líbano',
            'LR' => 'Liberia',
            'LY' => 'Libia',
            'LI' => 'Liechtenstein',
            'LT' => 'Lituania',
            'LU' => 'Luxemburgo',
            'MK' => 'Macedonia del Norte',
            'MG' => 'Madagascar',
            'MY' => 'Malasia',
            'MW' => 'Malaui',
            'MV' => 'Maldivas',
            'ML' => 'Malí',
            'MT' => 'Malta',
            'MA' => 'Marruecos',
            'MU' => 'Mauricio',
            'MR' => 'Mauritania',
            'MX' => 'México',
            'FM' => 'Micronesia',
            'MD' => 'Moldavia',
            'MC' => 'Mónaco',
            'MN' => 'Mongolia',
            'ME' => 'Montenegro',
            'MZ' => 'Mozambique',
            'MM' => 'Myanmar',
            'NA' => 'Namibia',
            'NR' => 'Nauru',
            'NP' => 'Nepal',
            'NI' => 'Nicaragua',
            'NE' => 'Níger',
            'NG' => 'Nigeria',
            'NO' => 'Noruega',
            'NZ' => 'Nueva Zelanda',
            'OM' => 'Omán',
            'NL' => 'Países Bajos',
            'PK' => 'Pakistán',
            'PW' => 'Palaos',
            'PA' => 'Panamá',
            'PG' => 'Papúa Nueva Guinea',
            'PY' => 'Paraguay',
            'PE' => 'Perú',
            'PL' => 'Polonia',
            'PT' => 'Portugal',
            'GB' => 'Reino Unido',
            'CF' => 'República Centroafricana',
            'CZ' => 'República Checa',
            'DO' => 'República Dominicana',
            'RW' => 'Ruanda',
            'RO' => 'Rumania',
            'RU' => 'Rusia',
            'WS' => 'Samoa',
            'KN' => 'San Cristóbal y Nieves',
            'SM' => 'San Marino',
            'VC' => 'San Vicente y las Granadinas',
            'LC' => 'Santa Lucía',
            'ST' => 'Santo Tomé y Príncipe',
            'SN' => 'Senegal',
            'RS' => 'Serbia',
            'SC' => 'Seychelles',
            'SL' => 'Sierra Leona',
            'SG' => 'Singapur',
            'SY' => 'Siria',
            'SO' => 'Somalia',
            'LK' => 'Sri Lanka',
            'ZA' => 'Sudáfrica',
            'SD' => 'Sudán',
            'SS' => 'Sudán del Sur',
            'SE' => 'Suecia',
            'CH' => 'Suiza',
            'SR' => 'Surinam',
            'TH' => 'Tailandia',
            'TZ' => 'Tanzania',
            'TJ' => 'Tayikistán',
            'TL' => 'Timor Oriental',
            'TG' => 'Togo',
            'TO' => 'Tonga',
            'TT' => 'Trinidad y Tobago',
            'TN' => 'Túnez',
            'TM' => 'Turkmenistán',
            'TR' => 'Turquía',
            'TV' => 'Tuvalu',
            'UA' => 'Ucrania',
            'UG' => 'Uganda',
            'UY' => 'Uruguay',
            'UZ' => 'Uzbekistán',
            'VU' => 'Vanuatu',
            'VA' => 'Vaticano',
            'VE' => 'Venezuela',
            'VN' => 'Vietnam',
            'YE' => 'Yemen',
            'DJ' => 'Yibuti',
            'ZM' => 'Zambia',
            'ZW' => 'Zimbabue',
        ];
    }

    public static function name(string $iso): ?string
    {
        $iso = strtoupper(trim($iso));

        return self::names()[$iso] ?? null;
    }

    public static function isoFromName(string $name): ?string
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        foreach (self::names() as $iso => $label) {
            if (strcasecmp($label, $name) === 0) {
                return $iso;
            }
        }

        return null;
    }

    /**
     * UI choices: value and label are the display name (not the ISO code).
     *
     * @return list<array{value: string, label: string}>
     */
    public static function choices(): array
    {
        $choices = [];
        foreach (self::names() as $name) {
            $choices[] = [
                'value' => $name,
                'label' => $name,
            ];
        }

        usort($choices, fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return $choices;
    }

    /**
     * Primary IANA timezone for a country ISO code. Falls back to UTC.
     */
    public static function timezone(?string $iso): string
    {
        $iso = strtoupper(trim((string) $iso));
        if ($iso === '' || self::name($iso) === null) {
            return 'UTC';
        }

        try {
            $zones = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $iso);
        } catch (\Throwable) {
            return 'UTC';
        }

        if ($zones === []) {
            return 'UTC';
        }

        // Prefer a capital/common zone when a country has several (e.g. Mexico, US).
        $preferred = match ($iso) {
            'MX' => 'America/Mexico_City',
            'US' => 'America/New_York',
            'CA' => 'America/Toronto',
            'BR' => 'America/Sao_Paulo',
            'ES' => 'Europe/Madrid',
            'PT' => 'Europe/Lisbon',
            'CL' => 'America/Santiago',
            'AR' => 'America/Argentina/Buenos_Aires',
            default => null,
        };

        if ($preferred !== null && in_array($preferred, $zones, true)) {
            return $preferred;
        }

        return $zones[0];
    }
}
