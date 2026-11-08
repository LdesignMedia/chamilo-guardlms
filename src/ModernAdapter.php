<?php

namespace GuardLMS\Chamilo;

/** Adapter for Chamilo 3's Symfony runtime and plugin repository. */
final class ModernAdapter extends Adapter
{
    public function modern()
    {
        return true;
    }
}
