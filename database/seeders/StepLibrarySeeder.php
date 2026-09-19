<?php

namespace Database\Seeders;

use App\Models\GarmentType;
use App\Models\ProductionStep;
use App\Models\StepTemplate;
use Illuminate\Database\Seeder;

/**
 * A starting library, so the platform is usable the day it is switched on.
 *
 * Real Nigerian garments and a real sequence of stages, rather than "Step one,
 * Step two" — an admin is far more likely to adjust a list that is already
 * roughly right than to build one from nothing, and a tailor opening this for
 * the first time should recognise what she is looking at.
 *
 * NONE OF THESE HAVE RECORDINGS. That is the admin's job and it cannot be
 * seeded: the whole point is a human voice saying what the step means. The
 * admin screen shows which steps are still silent.
 */
class StepLibrarySeeder extends Seeder
{
    /** Ordered as an admin would want them listed. */
    private const GARMENTS = [
        ['Agbada', 'Flowing wide-sleeved robe, usually three pieces'],
        ['Iro and buba', 'Wrapper and blouse, with gele and ipele'],
        ['Senator', 'Tailored kaftan and trousers'],
        ['Kaftan', 'Long loose tunic'],
        ['Ankara dress', 'Dress cut from printed cotton'],
        ['Wedding gown', 'Bridal, fitted, usually with beading'],
        ['Aso-oke set', 'Handwoven cloth, traditionally for ceremonies'],
        ['Skirt and blouse', 'Two-piece, everyday or office'],
        ['Shirt', 'Men\'s shirt, long or short sleeve'],
        ['Trousers', 'Tailored trousers'],
    ];

    /**
     * The library. Order here is only the order they are created in; which
     * steps a garment uses, and in what sequence, is the arrangement's job.
     */
    private const STEPS = [
        ['Fabric received', 'The customer has handed over the cloth. Count the yards and check for faults before she leaves.'],
        ['Measurements taken', 'Photograph the measurement book page. Write the customer name and the date on the page first.'],
        ['Pattern drafted', 'Draft the pattern onto paper or straight onto the cloth.'],
        ['Cutting', 'Cut the pieces. Keep the offcuts until the garment is collected.'],
        ['Embroidery', 'Machine or hand embroidery on the neck, chest or sleeves.'],
        ['Beading', 'Hand beading. Slow work — give it its own stage so the customer understands the wait.'],
        ['Sewing', 'Join the pieces.'],
        ['First fitting', 'Customer tries it on. Mark any adjustment with tailor chalk.'],
        ['Adjustments', 'Take in or let out what the fitting showed.'],
        ['Lining', 'Attach the lining.'],
        ['Buttons and fastenings', 'Buttons, zips, hooks.'],
        ['Finishing and pressing', 'Trim threads, press, fold.'],
        ['Ready to collect', 'Finished and bagged. The customer is told the moment this is ticked.'],
    ];

    /** Which steps each garment starts with, by label, in order. */
    private const ARRANGEMENTS = [
        'Agbada' => ['Fabric received', 'Measurements taken', 'Pattern drafted', 'Cutting', 'Embroidery', 'Sewing', 'First fitting', 'Adjustments', 'Finishing and pressing', 'Ready to collect'],
        'Iro and buba' => ['Fabric received', 'Measurements taken', 'Cutting', 'Sewing', 'First fitting', 'Adjustments', 'Finishing and pressing', 'Ready to collect'],
        'Senator' => ['Fabric received', 'Measurements taken', 'Pattern drafted', 'Cutting', 'Sewing', 'Buttons and fastenings', 'Finishing and pressing', 'Ready to collect'],
        'Kaftan' => ['Fabric received', 'Measurements taken', 'Cutting', 'Embroidery', 'Sewing', 'Finishing and pressing', 'Ready to collect'],
        'Ankara dress' => ['Fabric received', 'Measurements taken', 'Pattern drafted', 'Cutting', 'Sewing', 'First fitting', 'Adjustments', 'Finishing and pressing', 'Ready to collect'],
        'Wedding gown' => ['Fabric received', 'Measurements taken', 'Pattern drafted', 'Cutting', 'Beading', 'Sewing', 'Lining', 'First fitting', 'Adjustments', 'Buttons and fastenings', 'Finishing and pressing', 'Ready to collect'],
        'Aso-oke set' => ['Fabric received', 'Measurements taken', 'Cutting', 'Sewing', 'First fitting', 'Adjustments', 'Finishing and pressing', 'Ready to collect'],
        'Skirt and blouse' => ['Fabric received', 'Measurements taken', 'Cutting', 'Sewing', 'Buttons and fastenings', 'Finishing and pressing', 'Ready to collect'],
        'Shirt' => ['Fabric received', 'Measurements taken', 'Cutting', 'Sewing', 'Buttons and fastenings', 'Finishing and pressing', 'Ready to collect'],
        'Trousers' => ['Fabric received', 'Measurements taken', 'Cutting', 'Sewing', 'Buttons and fastenings', 'Finishing and pressing', 'Ready to collect'],
    ];

    public function run(): void
    {
        $steps = [];

        foreach (self::STEPS as [$label, $instructions]) {
            // firstOrCreate throughout: re-running must never undo an admin's
            // edits or duplicate a step somebody has already arranged.
            $steps[$label] = ProductionStep::firstOrCreate(
                ['label' => $label],
                ['instructions' => $instructions],
            );
        }

        foreach (self::GARMENTS as $position => [$name, $description]) {
            $type = GarmentType::firstOrCreate(
                ['slug' => GarmentType::uniqueSlug($name)],
                ['name' => $name, 'description' => $description, 'position' => $position + 1],
            );

            $template = StepTemplate::defaultFor($type);

            // Only seed the arrangement if nobody has touched it, so a
            // re-seed cannot rewrite a sequence an admin has adjusted.
            if ($template->items()->exists()) {
                continue;
            }

            $template->reorder(
                collect(self::ARRANGEMENTS[$name] ?? [])
                    ->map(fn (string $label) => $steps[$label]->id)
                    ->all(),
            );
        }
    }
}
