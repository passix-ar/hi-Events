<?php

namespace HiEvents\DomainObjects\Enums;

enum SocialAuthProvider: string
{
    use BaseEnum;

    case GOOGLE = 'google';
}
