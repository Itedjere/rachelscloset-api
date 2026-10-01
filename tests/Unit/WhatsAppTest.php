<?php

namespace Tests\Unit;

use App\Support\WhatsApp;
use PHPUnit\Framework\TestCase;

/**
 * wa.me wants the international number with no leading zero. Every copy of
 * this written by hand got it wrong, and a wrong one opens a chat with nobody.
 */
class WhatsAppTest extends TestCase
{
    public function test_a_stored_number_becomes_international(): void
    {
        $this->assertSame('https://wa.me/2348031234567', WhatsApp::to('08031234567'));
    }

    public function test_any_way_of_writing_it_lands_on_the_same_chat(): void
    {
        foreach (['08031234567', '+234 803 123 4567', '234-803-123-4567', '0803 123 4567'] as $written) {
            $this->assertSame('https://wa.me/2348031234567', WhatsApp::to($written), $written);
        }
    }

    public function test_a_message_is_encoded_after_the_number(): void
    {
        $this->assertSame(
            'https://wa.me/2348031234567?text=Hello%20Amaka',
            WhatsApp::to('08031234567', 'Hello Amaka'),
        );
    }

    public function test_a_share_link_has_no_recipient(): void
    {
        $this->assertSame('https://wa.me/?text=Hi', WhatsApp::share('Hi'));
    }
}
