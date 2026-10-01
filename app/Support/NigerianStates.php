<?php

namespace App\Support;

/**
 * Nigeria's 36 states and the FCT, spelled one way.
 *
 * One list because three things must agree on it: the directory's "near me"
 * filter, which is an exact equality on `tailor_profiles.state`; sign-up,
 * which writes that column; and the form that offers the choice. When
 * sign-up took free text, a tailor who typed "lagos" or "Lagos State" was
 * invisible to everybody filtering for Lagos -- the one search the directory
 * exists to answer.
 */
class NigerianStates
{
    public const ALL = [
        'Abia', 'Adamawa', 'Akwa Ibom', 'Anambra', 'Bauchi', 'Bayelsa', 'Benue',
        'Borno', 'Cross River', 'Delta', 'Ebonyi', 'Edo', 'Ekiti', 'Enugu',
        'FCT', 'Gombe', 'Imo', 'Jigawa', 'Kaduna', 'Kano', 'Katsina', 'Kebbi',
        'Kogi', 'Kwara', 'Lagos', 'Nasarawa', 'Niger', 'Ogun', 'Ondo', 'Osun',
        'Oyo', 'Plateau', 'Rivers', 'Sokoto', 'Taraba', 'Yobe', 'Zamfara',
    ];
}
