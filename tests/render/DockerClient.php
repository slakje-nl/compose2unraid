<?php

class DockerUpdate
{
    public function reloadUpdateStatus($image = null): void
    {
        if ($image === 'example/torn') {
            throw new RuntimeException('no route to host');
        }
    }
}

class DockerUtil
{
    public static function ensureImageTag($image): string
    {
        [$repo, $tag] = array_pad(explode(':', $image, 2), 2, 'latest');

        return (str_contains($repo, '/') ? $repo : 'library/' . $repo) . ':' . $tag;
    }
}
