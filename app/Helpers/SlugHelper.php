<?php

namespace App\Helpers;

use Illuminate\Support\Str;
use App\Models\Comunicazione;

class SlugHelper
{
    public static function generateSlug($subject): string
    {
        // Generate a basic slug from the subject
        $slug = Str::slug($subject);

        // Check if the slug already exists in the database
        if (Comunicazione::where('slug', $slug)->exists()) {
            
            // Find the most recent slug with the same base (title)
            $max = Comunicazione::where('slug', 'like', "{$slug}%")
                                ->latest('id')
                                ->value('slug');

            // If the latest slug ends with a number, increment it
            if (preg_match('/-(\d+)$/', $max, $matches)) {
                return preg_replace_callback('/-(\d+)$/', function ($matches) {
                    return '-' . ($matches[1] + 1);  // Increment the number
                }, $max);
            }

            // Otherwise, append '-2' to the slug (for the next duplicate)
            return "{$slug}-2";
        }

        // Return the original slug if it's unique
        return $slug;
    }
}


