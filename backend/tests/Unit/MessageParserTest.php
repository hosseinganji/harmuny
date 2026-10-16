<?php

namespace Tests\Unit;

use App\Services\Telegram\MessageParser;
use App\Support\TextNormalizer;
use PHPUnit\Framework\TestCase;

class MessageParserTest extends TestCase
{
    public function test_uses_audio_attributes(): void
    {
        $track = MessageParser::parse($this->message([
            ['_' => 'documentAttributeAudio', 'duration' => 245, 'title' => 'Gole Yakh', 'performer' => 'Kourosh Yaghmaei'],
            ['_' => 'documentAttributeFilename', 'file_name' => 'x.mp3'],
        ]));

        $this->assertSame('Gole Yakh', $track['title']);
        $this->assertSame('Kourosh Yaghmaei', $track['artist']);
        $this->assertSame(245, $track['duration']);
        $this->assertSame(42, $track['message_id']);
    }

    public function test_falls_back_to_file_name(): void
    {
        $track = MessageParser::parse($this->message([
            ['_' => 'documentAttributeAudio', 'duration' => 100],
            ['_' => 'documentAttributeFilename', 'file_name' => 'Googoosh - Pol.mp3'],
        ]));

        $this->assertSame('Pol', $track['title']);
        $this->assertSame('Googoosh', $track['artist']);
    }

    public function test_normalizer(): void
    {
        $this->assertSame('علی کریمی 12', TextNormalizer::normalize('عَلي  كريمي_۱۲'));
        $this->assertSame(['می', 'خواهم'], TextNormalizer::words("می\u{200C}خواهم"));
    }

    private function message(array $attributes): array
    {
        return [
            'id' => 42,
            'date' => 1700000000,
            'message' => '',
            'media' => ['document' => ['mime_type' => 'audio/mpeg', 'size' => 5000, 'attributes' => $attributes, 'thumbs' => []]],
        ];
    }
}
