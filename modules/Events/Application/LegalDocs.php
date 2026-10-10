<?php

declare(strict_types=1);

namespace Modules\Events\Application;

/** Canonical Czech cookies + GDPR + VOP texts seeded into CMS (editable in admin). */
final class LegalDocs
{
    public const REVISION = 4;

    public static function cookiesHtml(): string
    {
        return <<<'HTML'
<p>Tyto zásady vysvětlují, jak portál <strong>KdeAco</strong> používá cookies a podobné technologie (například localStorage) v souladu se zákonem č.&nbsp;127/2005&nbsp;Sb., o elektronických komunikacích, a nařízením (EU)&nbsp;2016/679 (GDPR).</p>
<h2>1. Správce</h2>
<p>{{ legal.controller_html }}</p>
<p>Podrobnosti o zpracování osobních údajů jsou v <a href="/stranka/ochrana-osobnich-udaju">informacích o ochraně osobních údajů</a>.</p>
<h2>2. Co jsou cookies a podobné technologie</h2>
<p>Cookies jsou malé textové soubory, které se ukládají do vašeho zařízení. Podobně funguje i localStorage v prohlížeči. Technicky nezbytné prostředky můžeme používat bez souhlasu. Ostatní (statistika, marketing, volitelné preference) jen tehdy, pokud k tomu dáte souhlas v liště cookies — a jen pokud jsou v administraci webu zapnuté.</p>
<h2>3. Jak dáváte a odvoláváte souhlas</h2>
<p>Při první návštěvě se zeptáme, než se spustí volitelné technologie. Souhlas je dobrovolný, lze ho odmítnout a kdykoli změnit:</p>
<ul>
<li>tlačítkem nastavení cookies v rohu webu,</li>
<li>odkazem <a href="#cookies">Nastavení cookies</a>,</li>
<li>nebo smazáním dat webu v prohlížeči.</li>
</ul>
<p>Odvolání souhlasu nemá vliv na zákonnost zpracování, které probíhalo před odvoláním. Tlačítko „Pouze nezbytné“ má stejnou váhu jako souhlas s volitelnými kategoriemi.</p>
<h2>4. Cookies a úložiště, které KdeAco skutečně používá</h2>
<table>
<thead>
<tr><th>Název</th><th>Typ</th><th>Doba</th><th>Účel</th><th>Základ</th></tr>
</thead>
<tbody>
<tr><td>gereq_session</td><td>HTTP cookie (administrace)</td><td>relace</td><td>Přihlášení správce do administrace</td><td>Nezbytné (provoz služby)</td></tr>
<tr><td>gereq_remember</td><td>HTTP cookie (administrace)</td><td>30 dní</td><td>Zapamatování přihlášení správce</td><td>Nezbytné (provoz služby)</td></tr>
<tr><td>gereq_store_token</td><td>HTTP cookie HttpOnly</td><td>7 dní</td><td>Přihlášení návštěvníka (účet KdeAco)</td><td>Nezbytné (plnění smlouvy)</td></tr>
<tr><td>kdeaco_store_token</td><td>localStorage</td><td>do odhlášení</td><td>Technický přístupový token k API</td><td>Nezbytné (plnění smlouvy)</td></tr>
<tr><td>kdeaco-cookie-consent</td><td>localStorage</td><td>do změny / smazání</td><td>Uložení vašeho rozhodnutí o cookies</td><td>Nezbytné (právní povinnost / souhlas)</td></tr>
</tbody>
</table>
<p>Tyto položky nelze v liště vypnout, protože bez nich nelze web bezpečně provozovat, přihlásit se ani zapamatovat, že jste cookies odmítli.</p>
<h2>5. Volitelné kategorie</h2>
<p>Statistické, marketingové a preferenční cookies na KdeAco ve výchozím stavu <strong>nepoužíváme</strong>. Pokud je provozovatel v administraci zapne, objeví se v liště a spustí se až po vašem souhlasu. Do té doby se nenačítají měřicí ani reklamní skripty třetích stran.</p>
<ul>
<li><strong>Preferenční</strong> — zapamatování vzhledu nebo filtrů nad rámec nezbytného chodu.</li>
<li><strong>Statistické</strong> — návštěvnost a použití webu (např. anonymizovaná analytika).</li>
<li><strong>Marketingové</strong> — měření kampaní a personalizace.</li>
</ul>
<h2>6. Přihlášení přes sociální sítě</h2>
<p>Pokud se přihlásíte přes Google, Facebook nebo GitHub, tyto služby mohou na svých doménách nastavit vlastní cookies podle svých zásad. KdeAco od nich přebírá jen údaje potřebné k založení nebo propojení účtu (typicky jméno a e-mail).</p>
<h2>7. Prohlížeč</h2>
<p>Cookies můžete omezit i v nastavení prohlížeče. Blokování nezbytných cookies může znemožnit přihlášení nebo odeslání formuláře.</p>
<p>Účinnost: 9.&nbsp;10.&nbsp;2026. Text můžete upravit v administraci CMS (stránka „Zásady cookies“).</p>
HTML;
    }

    public static function privacyHtml(): string
    {
        return <<<'HTML'
<p>Tento dokument plní informační povinnost správce podle čl.&nbsp;13 a 14 nařízení (EU)&nbsp;2016/679 (GDPR) a souvisejících předpisů České republiky.</p>
<h2>1. Správce osobních údajů</h2>
<p>{{ legal.controller_html }}</p>
<p>Pověřence pro ochranu osobních údajů jsme nejmenovali. Dotazy ke zpracování posílejte na e-mail správce, nebo přes <a href="/kontakt">kontaktní formulář</a>.</p>
<h2>2. Jaké údaje zpracováváme a proč</h2>
<table>
<thead>
<tr><th>Účel</th><th>Údaje</th><th>Právní základ (čl.&nbsp;6 GDPR)</th></tr>
</thead>
<tbody>
<tr><td>Založení a správa uživatelského účtu, přihlášení (e-mail / sociální síť), uložené akce, návrhy akcí</td><td>jméno, e-mail, telefon, heslo (hash), preference kraje a témat, obsah návrhu akce, identifikátory přihlášení</td><td>plnění smlouvy (uživatelský účet)</td></tr>
<tr><td>Provoz veřejného katalogu akcí (zveřejnění popisů, termínů, míst, odkazů, náhledů)</td><td>texty a metadata akce; u uživatelských návrhů také údaje vkladatele</td><td>plnění smlouvy s vkladatelem (uživatelský návrh); oprávněný zájem na provozu veřejného katalogu (včetně akcí zařazených provozovatelem)</td></tr>
<tr><td>Zveřejnění fotografií, na nichž jsou rozpoznatelné osoby</td><td>podoba / vyobrazení osob na snímku, případně související popis</td><td>smlouva s vkladatelem sama nepokrývá osoby na fotografii; právní základ posuzujeme pro konkrétní použití — typicky oprávněný zájem na informování o akci (po vyvážení zájmů), souhlas dotčené osoby, nebo jiný zákonný podklad; vkladatel musí mít potřebná oprávnění dle obchodních podmínek</td></tr>
<tr><td>Vyřízení zprávy z kontaktního formuláře (včetně hlášení závadného obsahu)</td><td>jméno, e-mail, téma, text zprávy</td><td>oprávněný zájem na komunikaci / kroky před smlouvou; právní povinnost u oznámení protiprávního obsahu</td></tr>
<tr><td>Odběr novinek (tipy na akce)</td><td>e-mail, jméno, zvolený kraj a témata</td><td>souhlas (lze kdykoli odvolat)</td></tr>
<tr><td>Zabezpečení webu, přihlášení, uložení volby cookies</td><td>technické identifikátory, IP v provozních logech, cookies dle zásad cookies</td><td>oprávněný zájem na bezpečném provozu; u volitelných cookies souhlas</td></tr>
<tr><td>Žádost o výmaz / uplatnění práv</td><td>identifikace žadatele, obsah žádosti</td><td>právní povinnost (GDPR)</td></tr>
<tr><td>Administrace katalogu</td><td>údaje účtů správců v interním systému</td><td>oprávněný zájem / plnění smlouvy se správcem</td></tr>
</tbody>
</table>
<p>Účet není určen dětem mladším 16 let. Osobní údaje zvláštní kategorie (čl.&nbsp;9 GDPR) záměrně nesbíráme.</p>
<h2>3. Fotografie a údaje v katalogu</h2>
<p>Katalog KdeAco obsahuje texty a fotografie akcí, které:</p>
<ul>
<li>vloží přihlášený uživatel (návrh nebo úprava akce),</li>
<li>nebo do katalogu zařadí provozovatel KdeAco z oprávněných zdrojů.</li>
</ul>
<p>Fotografie a související údaje zpracováváme za účelem zveřejnění akce, tvorby náhledů, vyhledávání a běžné propagace dané akce na KdeAco. Veřejný obsah je dostupný návštěvníkům webu.</p>
<p>Pokud uživatel vloží obsah (včetně fotografií či údajů o třetích osobách) a určuje, co má být zveřejněno, odpovídá za oprávnění k takovému vložení podle <a href="/stranka/obchodni-podminky">obchodních podmínek</a>. KdeAco v takovém případě obsah technicky zpracovává a zveřejňuje v rámci katalogu.</p>
<p><strong>Osoby na fotografii:</strong> smlouva nebo vztah s vkladatelem sám o sobě nepokrývá zpracování osobních údajů lidí, kteří jsou na snímku rozpoznatelní. Pro zveřejnění takové fotografie musí existovat samostatný právní základ vůči těmto osobám. KdeAco oprávněný zájem na informování o akci posuzuje podle konkrétního použití (např. plakát akce, dokumentační snímek z veřejného programu, detailní portrét). Kde oprávněný zájem neobstojí, vyžadujeme souhlas dotčené osoby nebo jiný zákonný podklad, anebo fotografii nezveřejníme / odstraníme. Vkladatel prohlašuje, že k tomu má potřebná oprávnění a svolení.</p>
<p>U akcí, které do katalogu zařadí sám provozovatel KdeAco, je nutné mít oprávnění od zdroje nebo jiný zákonný podklad. Potvrzení vkladatele u uživatelských návrhů takové akce nepokryje.</p>
<p>Jste-li na fotografii v katalogu a nesouhlasíte se zveřejněním, napište nám přes <a href="/kontakt">kontakt</a>. Hlášení posoudíme a snímek podle okolností skryjeme, upravíme nebo odstraníme.</p>
<h2>4. Z jakých zdrojů údaje máme</h2>
<p>Především od vás (registrace, profil, formuláře, návrh akce včetně fotografií). Při přihlášení přes Google, Facebook nebo GitHub obdržíme identifikační a kontaktní údaje, které nám poskytovatel předá na základě vašeho pokynu u něj. Do katalogu mohou být zařazeny také veřejné informace o akcích z oprávněných zdrojů, pokud k tomu máme potřebná práva nebo jiný zákonný důvod.</p>
<h2>5. Kdo k údajům může mít přístup</h2>
<ul>
<li>provozovatel KdeAco a pověření spolupracovníci,</li>
<li>poskytovatel provozní platformy <strong>GEREQ.cz</strong> (zpracovatel — hosting, e-mailová a technická infrastruktura),</li>
<li>poskytovatelé přihlášení (Google, Meta/Facebook, GitHub) v rozsahu, v jakém u nich účet používáte — ti zpravidla jednají jako samostatní správci svých služeb,</li>
<li>návštěvníci webu u údajů, které jsou součástí zveřejněného katalogu,</li>
<li>orgány veřejné moci, pokud to ukládá zákon.</li>
</ul>
<p>Údaje nepředáváme k obchodu s adresami. Pokud zpracovatel sídlí mimo EHP (typicky USA u sociálních přihlášení), předání se opírá o rozhodnutí o odpovídající ochraně nebo o standardní smluvní doložky a opatření daného poskytovatele.</p>
<h2>6. Jak dlouho údaje držíme</h2>
<ul>
<li>účet a s ním spojené uložené akce — po dobu existence účtu; po smazání účtu údaje smažeme nebo anonymizujeme, ledaže zákon ukládá delší archivaci,</li>
<li>zveřejněný text akce v katalogu — po dobu zveřejnění; po skončení akce (podle data konce, jinak podle data začátku) může zůstat v archivu katalogu nejdéle <strong>24 měsíců</strong>, poté ji odpublikujeme,</li>
<li>fotografie a náhledy — stejně jako text akce: po dobu zveřejnění a nejdéle <strong>24 měsíců od skončení akce</strong>; poté fotografie smažeme nebo anonymizujeme. Výjimkou je situace, kdy je potřebujeme déle k obhajobě právních nároků nebo ke splnění zákonné povinnosti — tehdy je držíme v omezeném režimu nejdéle další <strong>3 roky</strong>,</li>
<li>návrhy akcí odmítnuté nebo neschválené — zpravidla nejdéle <strong>90 dní</strong> od rozhodnutí, poté je smažeme včetně nahraných fotografií,</li>
<li>kontaktní zprávy a hlášení obsahu — zpravidla 3 roky od vyřízení (obhajoba právních nároků),</li>
<li>odběr novinek — do odvolání souhlasu / odhlášení,</li>
<li>provozní logy — po dobu nezbytnou k zabezpečení, zpravidla nejdéle 90 dní,</li>
<li>záznam o souhlasu s cookies — po dobu, než souhlas změníte nebo smažete data webu.</li>
</ul>
<h2>7. Vaše práva</h2>
<p>Máte právo na přístup, opravu, výmaz, omezení zpracování, přenositelnost, námitku proti zpracování založenému na oprávněném zájmu a právo kdykoli odvolat souhlas (novinky, volitelné cookies). Odvolání souhlasu neovlivní zpracování před odvoláním.</p>
<p>Práva uplatníte v <a href="/profil/nastaveni">nastavení účtu</a> (oprava údajů, žádost o výmaz, smazání účtu), e-mailem správce nebo přes <a href="/kontakt">kontakt</a>. Odpovíme bez zbytečného odkladu, nejpozději do jednoho měsíce.</p>
<p>Máte také právo podat stížnost u Úřadu pro ochranu osobních údajů, Pplk.&nbsp;Sochora 27, 170&nbsp;00 Praha&nbsp;7, <a href="https://www.uoou.gov.cz">www.uoou.gov.cz</a>.</p>
<h2>8. Automatizované rozhodování</h2>
<p>Neděláme automatizovaná rozhodnutí s právními účinky ve smyslu čl.&nbsp;22 GDPR, ani profilování k takovému rozhodnutí.</p>
<h2>9. Cookies</h2>
<p>Podrobnosti o cookies, localStorage a souhlasu jsou v <a href="/stranka/cookies">zásadách cookies</a>. Souhlas změníte v <a href="#cookies">nastavení cookies</a>.</p>
<p>Související pravidla k obsahu katalogu a právům k fotografiím jsou v <a href="/stranka/obchodni-podminky">obchodních podmínkách</a>.</p>
<p>Účinnost: 9.&nbsp;10.&nbsp;2026. Text upravíte v administraci CMS (stránka „Ochrana osobních údajů“), identitu správce ve WebPlus → GDPR.</p>
HTML;
    }

    public static function termsHtml(): string
    {
        return <<<'HTML'
<p>Tyto obchodní podmínky (dále jen „podmínky“) upravují užívání katalogu akcí <strong>KdeAco</strong> (dále jen „služba“) provozovaného správcem uvedeným níže.</p>
<h2>1. Provozovatel</h2>
<p>{{ legal.controller_html }}</p>
<p>Kontakt: <a href="/kontakt">kontaktní formulář</a> nebo e-mail správce.</p>
<h2>2. Co KdeAco nabízí</h2>
<p>KdeAco je komunitní katalog akcí především v Plzeňském kraji. Umožňuje prohlížení akcí, založení uživatelského účtu, uložení oblíbených akcí a vložení nebo úpravu návrhu akce včetně textů a fotografií.</p>
<p>Zveřejnění uživatelského návrhu může podléhat schválení provozovatelem. Provozovatel může obsah upravit, odmítnout nebo později odpublikovat, pokud porušuje tyto podmínky, právo nebo dobré mravy, anebo je zjevně chybný.</p>
<h2>3. Účet a užívání služby</h2>
<p>Účet je osobní. Za přihlašovací údaje odpovídáte vy. Službu nesmíte používat k šíření spamu, malware, k porušování práv třetích osob ani k jinému protiprávnímu jednání.</p>
<p>Provozovatel může účet omezit nebo zrušit při závažném nebo opakovaném porušení podmínek.</p>
<h2>4. Vkládání obsahu — licence a oprávnění</h2>
<p>Nahráním, vložením nebo odesláním obsahu (zejména textů, fotografií, plakátů, videonáhledů a odkazů) udělujete provozovateli KdeAco nevýhradní, územně neomezenou licenci na dobu existence obsahu ve službě, a to v rozsahu potřebném k provozu katalogu, zejména:</p>
<ul>
<li>uložení a zpracování obsahu,</li>
<li>veřejné zveřejnění v katalogu KdeAco,</li>
<li>vytváření a zobrazování náhledů a zmenšenin,</li>
<li>běžnou propagaci dané akce v rámci služby KdeAco (včetně sdílení odkazu na akci).</li>
</ul>
<p>Licence není převoditelná mimo provoz služby a neslouží k prodeji fotografií třetím osobám jako samostatného díla.</p>
<p>Vkladatel zároveň prohlašuje a zaručuje, že:</p>
<ul>
<li>má k obsahu všechna potřebná práva (včetně autorských a souvisejících),</li>
<li>má svolení osob rozpoznatelných na fotografii, nebo jiný zákonný podklad pro jejich zveřejnění — vztah s KdeAco sám o sobě osoby na snímku nepokrývá,</li>
<li>vložením a zveřejněním neporušuje práva třetích osob ani právní předpisy,</li>
<li>fotografie a texty se vztahují k uváděné akci a nejsou zavádějící.</li>
</ul>
<p>Odpovědnost za oprávnění k vloženému obsahu nese vkladatel. Provozovatel není povinen předem ověřovat každé oprávnění, může však obsah odmítnout nebo odstranit. Doba zveřejnění fotografií v katalogu se řídí pravidly uchování v <a href="/stranka/ochrana-osobnich-udaju">informacích o ochraně osobních údajů</a> (zpravidla nejdéle 24 měsíců od skončení akce).</p>
<p>Odesláním návrhu nebo úpravy akce potvrzujete souhlas s těmito podmínkami.</p>
<h2>5. Přebírání a zveřejňování obsahu z jiných zdrojů</h2>
<p>KdeAco může do katalogu zařazovat informace o akcích také z jiných zdrojů, ale jen tehdy, když je to zákonem dovoleno, nebo když k tomu má provozovatel potřebné oprávnění (například souhlas, licenci či jinou dohodu se zdrojem).</p>
<p>Veřejná dostupnost fotografie nebo popisu na internetu sama o sobě neznamená, že ji lze volně převzít. Odpovědnost za oprávnění k obsahu, který KdeAco samo vybere a zveřejní ve vlastním katalogu, posuzuje provozovatel podle skutečného způsobu importu a publikování.</p>
<p>Potvrzení vkladatele u uživatelských návrhů nepokrývá akce, které do katalogu zařadí sám provozovatel.</p>
<h2>6. Pravidla obsahu</h2>
<p>Do služby nesmíte vkládat obsah, který:</p>
<ul>
<li>porušuje autorská, osobnostní nebo jiná práva třetích osob,</li>
<li>je nezákonný, nenávistný, vulgární nad míru kontextu nebo zavádějící,</li>
<li>slouží výhradně k reklamě nesouvisející s konkrétní akcí, spamem či podvodem,</li>
<li>obsahuje malware, skryté skripty nebo podobná rizika.</li>
</ul>
<h2>7. Hlášení závadného obsahu a jeho odstranění</h2>
<p>Pokud se domníváte, že obsah v katalogu porušuje právo (například autorská práva k fotografii) nebo tyto podmínky, nahlaste ho přes <a href="/kontakt">kontaktní formulář</a> nebo e-mailem správce. Uveďte prosím:</p>
<ul>
<li>odkaz na konkrétní akci nebo popis místa, kde se obsah nachází,</li>
<li>důvod hlášení,</li>
<li>vaše kontaktní údaje,</li>
<li>u nároku z práv třetích osob důvody, proč se domníváte, že jde o porušení (a pokud možno doklad oprávnění).</li>
</ul>
<p>Provozovatel hlášení posoudí bez zbytečného odkladu. Při důvodném podezření na protiprávní nebo závadný obsah může obsah dočasně skrýt, upravit, odstranit nebo odmítnout další zveřejnění. O výsledku vás podle okolností informujeme.</p>
<p>Opakovaná zjevně neoprávněná hlášení mohou být odmítnuta.</p>
<h2>8. Dostupnost služby</h2>
<p>Snažíme se o spolehlivý provoz, ale nezaručujeme nepřetržitou dostupnost. Údržba, výpadky nebo změny rozsahu služby mohou nastat. Katalog není oficiálním úředním seznamem akcí — údaje mohou být neúplné nebo zastaralé.</p>
<h2>9. Ochrana osobních údajů</h2>
<p>Zpracování osobních údajů popisují <a href="/stranka/ochrana-osobnich-udaju">informace o ochraně osobních údajů</a> a <a href="/stranka/cookies">zásady cookies</a>.</p>
<h2>10. Změny podmínek</h2>
<p>Podmínky můžeme aktualizovat. Aktuální znění je vždy na této stránce. Podstatné změny oznámíme přiměřeným způsobem (například na webu). Pokračováním v užívání služby po účinnosti změn přijímáte nové znění v rozsahu, v jakém to dovoluje právo.</p>
<p>Účinnost: 9.&nbsp;10.&nbsp;2026. Text můžete upravit v administraci CMS (stránka „Obchodní podmínky“).</p>
HTML;
    }
}
