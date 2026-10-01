<?php

/*
 * Placeholder photography for the public site.
 *
 * Every one of these is a Pexels photograph of Nigerian tailoring or Nigerian
 * traditional dress -- agbada, lace, aso-oke, coral beads, a Butterfly machine
 * in an Abuja workshop. That is not incidental. The first set of stock images
 * considered here was generic Western fashion (a blonde with shopping bags, a
 * stack of jeans) and it made the page look like a template for somebody
 * else's business. The photographs are the single biggest thing deciding
 * whether this reads as a Nigerian fashion house or as a bought theme.
 *
 * THESE ARE PLACEHOLDERS. Replacing them with Rachel's own work is the single
 * highest-value change available to this site, and it is a one-line edit per
 * image. To swap one, change `id` to a local path and `origin` to null.
 *
 * Sized through Pexels' own resizing parameters so a phone is never sent a
 * 4000px original -- `w` is the width actually requested, and `srcset` offers
 * the browser two.
 *
 * ORIENTATION MATTERS. The frame ratio in the Blade template must match the
 * source, or `object-fit: cover` throws away the difference. A landscape
 * workshop photograph in a `tall` 3:4 frame crops to somebody's forearm --
 * which is exactly what happened here before this note existed. Current set:
 *
 *   portrait (~0.67-0.75)  hero, cta, craft, shop, shop_inset,
 *                          every lookbook and tailor image
 *   landscape (1.5)        craft_inset -- used in a `square` frame, which
 *                          crops the sides evenly and suits a detail shot
 */

return [

    /*
     * Hero and closing call to action. Wide crops, because they are used
     * full-bleed behind type.
     */
    'hero' => ['id' => 39054376, 'alt' => 'A woman in embroidered Nigerian dress with coral beads'],
    'cta' => ['id' => 38500516, 'alt' => 'A seamstress at work with bright fabric at a Lagos market'],

    /* The editorial sections. */
    'craft' => ['id' => 39289632, 'alt' => 'A seamstress at a vintage sewing machine outdoors'],
    'craft_inset' => ['id' => 11482148, 'alt' => 'Close hands guiding fabric under a needle'],
    'shop' => ['id' => 11645426, 'alt' => 'A tailor smiling at his sewing machine in his shop'],
    'shop_inset' => ['id' => 38632982, 'alt' => 'A textile shop hung with rolls of patterned cloth'],

    /*
     * The lookbook. Order matters: the grid gives the first and sixth tiles a
     * double row, so those two want portraits with room at the top.
     */
    'lookbook' => [
        ['id' => 29997421, 'alt' => 'A woman in a blue and gold patterned dress with a lace shawl'],
        ['id' => 31951217, 'alt' => 'A woman in traditional attire with jewellery and a head wrap'],
        ['id' => 37810843, 'alt' => 'A man in a pale grey embroidered agbada and cap'],
        ['id' => 37036207, 'alt' => 'A woman in bright traditional dress holding a fan'],
        ['id' => 33624748, 'alt' => 'A man in a purple agbada and matching cap'],
        ['id' => 38395864, 'alt' => 'A woman in traditional attire with beads and a decorative umbrella'],
        ['id' => 29553408, 'alt' => 'A woman in a patterned traditional dress'],
        ['id' => 12447951, 'alt' => 'A woman posing in vibrant Nigerian dress in a studio'],
    ],

    /*
     * Testimonials.
     *
     * Deliberately no photographs. A stock headshot pinned to an invented name
     * is the point at which placeholder content stops being obviously
     * placeholder and starts looking like a fabricated review -- and this
     * platform has a whole section riding on reviews being trustworthy. Text,
     * a rating and a location carry the card perfectly well on their own.
     *
     * Illustrative copy, to be replaced with real quotes before launch.
     */
    'testimonials' => [
        ['quote' => 'I stopped answering the same question forty times a week. They can see it for themselves now.', 'name' => 'Blessing O.', 'role' => 'Tailor', 'where' => 'Enugu', 'rating' => 5],
        ['quote' => 'My aso-ebi was ready three days before the wedding. I knew because my phone told me, not because I went there.', 'name' => 'Amaka N.', 'role' => 'Customer', 'where' => 'Lagos', 'rating' => 5],
        ['quote' => 'I photograph the measurement book and it is saved. I no longer ask a customer to come back just to be measured again.', 'name' => 'Ibrahim S.', 'role' => 'Tailor', 'where' => 'Kano', 'rating' => 5],
        ['quote' => 'I paid half and the rest stayed with Rachels Closet until I collected. That is the part that made me try it.', 'name' => 'Chioma E.', 'role' => 'Customer', 'where' => 'Port Harcourt', 'rating' => 4],
        ['quote' => 'Customers used to arrive on the wrong day and meet nothing ready. That does not happen any more.', 'name' => 'Taiwo A.', 'role' => 'Tailor', 'where' => 'Ibadan', 'rating' => 5],
    ],

    /*
     * Directory teaser. Invented tailors -- the real directory arrives in
     * Section 15 and will read from tailor_profiles. Named and located
     * plausibly rather than as "Tailor One", because a row of placeholder
     * names is the thing that makes a demo look unfinished.
     */
    'tailors' => [
        ['name' => 'Mama Ngozi Couture', 'where' => 'Enugu', 'rating' => 5, 'id' => 31884483, 'alt' => 'A woman in a striped outfit standing outdoors'],
        ['name' => 'Adaeze Bridal', 'where' => 'Lagos', 'rating' => 5, 'id' => 34214461, 'alt' => 'A woman in colourful traditional attire holding a fan'],
        ['name' => 'Bello & Sons', 'where' => 'Kano', 'rating' => 4, 'id' => 34550152, 'alt' => 'A man in brown traditional attire'],
        ['name' => 'House of Amaka', 'where' => 'Abuja', 'rating' => 5, 'id' => 39134398, 'alt' => 'A woman in traditional Nigerian clothing seated on a chair'],
        ['name' => 'Chidi Tailoring', 'where' => 'Port Harcourt', 'rating' => 4, 'id' => 12659984, 'alt' => 'An artisan sewing on a vintage machine'],
    ],
];
