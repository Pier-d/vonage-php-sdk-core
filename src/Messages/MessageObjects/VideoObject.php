<?php

namespace Vonage\Messages\MessageObjects;

use Vonage\Entity\Hydrator\ArrayHydrateInterface;

class VideoObject implements ArrayHydrateInterface
{
    public function __construct(private string $url, private string $caption = '')
    {
    }

    public function fromArray(array $data): VideoObject
    {
        $this->url = $data['url'];

        if (isset($data['caption'])) {
            $this->caption = $data['caption'];
        }

        return $this;
    }

    public function toArray(): array
    {
        $returnArray = [
            'url' => $this->url
        ];

        if ($this->caption) {
            $returnArray[] = [
                'caption' => $this->caption
            ];
        }

        return $returnArray;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getCaption(): string
    {
        return $this->caption;
    }
}
