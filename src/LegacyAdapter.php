<?php

namespace GuardLMS\Chamilo;

/** Adapter for the native 1.11 runtime. */
final class LegacyAdapter extends Adapter
{
    public function modern()
    {
        return false;
    }
}
