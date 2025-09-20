# AnubisBB
[Anubis homepage](https://anubis.techaro.lol) <br>
[Extension homepage](https://www.phpbb.com/community/viewtopic.php?t=2662765)

## Overview

Anubis [weighs the soul of your connection](https://en.wikipedia.org/wiki/Weighing_of_souls) using a proof-of-work
challenge in order to protect upstream resources from scraper bots.

This program is designed to help protect the small internet from the endless storm of requests that flood in from AI
companies. Anubis is as lightweight as possible to ensure that everyone can afford to protect the communities closest to
them.

Anubis is a bit of a nuclear response. This will result in your website being blocked from smaller scrapers and may
inhibit "good bots" like the Internet Archive. In most cases, you should not need this and can probably get by using Cloudflare to protect a given origin. However, for
circumstances where you can't or won't use Cloudflare, Anubis is there for you.

If you want to try this out, connect to [anubis.techaro.lol](https://anubis.techaro.lol).

## phpBB Port

After the many reports of forums suffering from 1000's of bots hitting their forums, a solution was needed. Thus, Anubis
was adapted for use as an extension for phpBB in the hope of reducing bot attacks. For the most part this extension
should behave in much the same way as its standalone counterpart.
It is currently based on version 1.18.0 of Anubis.

## Requirements

This extension depends on the `sodium` PHP extension. This extension is included by default in most php installations
since 7.2.0, although it may be disabled. It can be enabled by editing the `php.ini` config file or by downloading it
from your package manager.

## Installation

Download the extension to the ext folder and unzip it there. The file will unpack inside the ext folder to
neodev/anubisbb.

Go to "Administration Control Panel" > "Customise" > "Extensions" and enable the "AnubisBB" extension.

## License

[GNU General Public License v2](license.txt)
