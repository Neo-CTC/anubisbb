# AnubisBB

## Overview

Anubis [weighs the soul of your connection](https://en.wikipedia.org/wiki/Weighing_of_souls) using a proof-of-work challenge in order to protect upstream resources from scraper bots.

This program is designed to help protect the small internet from the endless storm of requests that flood in from AI companies. Anubis is as lightweight as possible to ensure that everyone can afford to protect the communities closest to them.

Anubis is a bit of a nuclear response. This will result in your website being blocked from smaller scrapers and may inhibit "good bots" like the Internet Archive. You can configure [bot policy definitions](./docs/docs/admin/policies.mdx) to explicitly allowlist them and we are working on a curated set of "known good" bots to allow for a compromise between discoverability and uptime.

In most cases, you should not need this and can probably get by using Cloudflare to protect a given origin. However, for circumstances where you can't or won't use Cloudflare, Anubis is there for you.

If you want to try this out, connect to [anubis.techaro.lol](https://anubis.techaro.lol).

## phpBB Port

After the many reports of forum admins suffering from 1000's of bots hitting their forums, a solution was needed. Thus, Anubis was adapted for use as an extension for phpBB in the hope of reducing bot attacks. For the most part this extension should behave in much the same way as it's standalone counterpart. 

## Installation

Download the extention to the ext folder and unzip it there. The file will unpack to ext/neodev/anubisbb

Go to "ACP" > "Customise" > "Extensions" and enable the "AnubisBB" extension.

## License

[GNU General Public License v2](license.txt)
