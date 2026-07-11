<?php
/**
 * WP-CLI `wp rd seed` command.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Cli;

use RubenDance\Lang;
use RubenDance\Post_Types;
use RubenDance\Repositories\Course_Term_Repository;
use RubenDance\Repositories\Location_Repository;
use RubenDance\Services\Duplicate_Enrollment_Exception;
use RubenDance\Services\Enrollment_Service;
use RubenDance\Services\Registration_Service;
use RubenDance\Services\Term_Service;
use RubenDance\Taxonomies;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/**
 * Class Seed_Command.
 *
 * Registers the `wp rd seed` command. Each milestone that adds an entity
 * extends `__invoke()` with that entity's fixture data; every seed method
 * must stay idempotent (re-running `wp rd seed` must never create
 * duplicates), matched by name against what's already in the database.
 */
class Seed_Command {

	/**
	 * Real venues from ruben-dance.cz's "Tančírny v Praze" page, used as
	 * fixture data so admin screens are always exercised against realistic
	 * content rather than "Location 1", "Location 2", ...
	 *
	 * @var array<int, array{name: string, address: string, map_url: string}>
	 */
	const LOCATIONS = array(
		array(
			'name'    => 'Terasa Smíchov',
			'address' => 'Plzeňská 8, 150 00 Praha 5 – Smíchov',
			'map_url' => 'https://maps.google.com/?q=Plze%C5%88sk%C3%A1+8%2C+150+00+Praha+5',
		),
		array(
			'name'    => 'NYX Hotel Prague',
			'address' => 'Panská 892/9, 110 00 Praha 1 – Nové Město',
			'map_url' => 'https://maps.google.com/?q=Pansk%C3%A1+892%2F9%2C+110+00+Praha+1',
		),
		array(
			'name'    => 'Terasa 67 (Křižíkův pavilon B)',
			'address' => 'Výstaviště 67, 170 00 Praha 7 – Holešovice',
			'map_url' => 'https://maps.google.com/?q=V%C3%BDstavi%C5%A1t%C4%9B+67%2C+170+00+Praha+7',
		),
	);

	/**
	 * Four courses covering the styles ruben-dance.cz actually teaches, used
	 * as fixture data (spec §3.1: course = "name, description, level, dance
	 * style"). Each becomes a CS post plus its EN translation, linked via
	 * Polylang when available (see `seed_courses()`).
	 *
	 * @var array<int, array{
	 *     title_cs: string,
	 *     title_en: string,
	 *     content_cs: string,
	 *     content_en: string,
	 *     style_cs: string,
	 *     style_en: string,
	 *     level_cs: string,
	 *     level_en: string,
	 * }>
	 */
	const COURSES = array(
		array(
			'title_cs'   => 'Salsa pro začátečníky',
			'title_en'   => 'Salsa for Beginners',
			'content_cs' => 'Základy kubánské salsy — držení, základní krok a první točení. Žádné předchozí zkušenosti nejsou potřeba.',
			'content_en' => 'The fundamentals of Cuban-style salsa — frame, basic step and first turns. No previous experience required.',
			'style_cs'   => 'Salsa',
			'style_en'   => 'Salsa',
			'level_cs'   => 'Začátečníci',
			'level_en'   => 'Beginners',
		),
		array(
			'title_cs'   => 'Bachata pro mírně pokročilé',
			'title_en'   => 'Bachata for Intermediate Dancers',
			'content_cs' => 'Navazuje na úplné základy bachaty: složitější figury, práce s partnerem a muzikalita.',
			'content_en' => 'Building on the absolute basics of bachata: more advanced figures, partner work and musicality.',
			'style_cs'   => 'Bachata',
			'style_en'   => 'Bachata',
			'level_cs'   => 'Mírně pokročilí',
			'level_en'   => 'Intermediate',
		),
		array(
			'title_cs'   => 'Dětský tanec',
			'title_en'   => 'Kids Dance',
			'content_cs' => 'Pohybová a taneční příprava pro děti — rytmus, koordinace a radost z tance formou hry.',
			'content_en' => 'Movement and dance preparation for children — rhythm, coordination and the joy of dance through play.',
			'style_cs'   => 'Dětský tanec',
			'style_en'   => 'Kids Dance',
			'level_cs'   => 'Pro všechny',
			'level_en'   => 'All levels',
		),
		array(
			'title_cs'   => 'Dámský styling',
			'title_en'   => 'Ladies Styling',
			'content_cs' => 'Ženský styl, práce s tělem a sólové prvky do latinskoamerických tanců — bez partnera.',
			'content_en' => 'Feminine styling, body movement and solo elements for Latin dances — no partner needed.',
			'style_cs'   => 'Dámský styling',
			'style_en'   => 'Ladies Styling',
			'level_cs'   => 'Pro všechny',
			'level_en'   => 'All levels',
		),
	);

	/**
	 * Six course terms spanning the four seeded courses (M05: "mix of
	 * statuses, one with early-bird, one with pair discount, one workshop,
	 * one at low capacity"). Field values are strings, the same shape
	 * `Term_Service::validate()`/`row()` expect from a real admin form
	 * submission, so this fixture data is validated exactly like a real save
	 * rather than bypassing that logic.
	 *
	 * @var array<int, array<string, string>>
	 */
	const TERMS = array(
		// Open, with an early-bird discount.
		array(
			'course_title_cs' => 'Salsa pro začátečníky',
			'location_name'   => 'Terasa Smíchov',
			'type'            => Term_Service::TYPE_COURSE,
			'weekday'         => '1', // Monday.
			'start_time'      => '18:00',
			'end_time'        => '19:00',
			'date_from'       => '2025-09-01',
			'date_to'         => '2025-12-15',
			'instructor'      => 'Ruben García',
			'capacity'        => '20',
			'price'           => '2400',
			'discount_early'  => '300',
			'early_until'     => '2025-08-15',
			'discount_pair'   => '',
			'status'          => Term_Service::STATUS_OPEN,
			'season_label_cs' => 'Podzim 2025',
			'season_label_en' => 'Autumn 2025',
			'note_public_cs'  => 'S sebou taneční obuv.',
			'note_public_en'  => 'Please bring dance shoes.',
		),
		// Draft: next season, not yet published.
		array(
			'course_title_cs' => 'Salsa pro začátečníky',
			'location_name'   => 'NYX Hotel Prague',
			'type'            => Term_Service::TYPE_COURSE,
			'weekday'         => '3', // Wednesday.
			'start_time'      => '19:00',
			'end_time'        => '20:00',
			'date_from'       => '2026-02-02',
			'date_to'         => '2026-05-18',
			'instructor'      => 'Ruben García',
			'capacity'        => '16',
			'price'           => '2400',
			'discount_early'  => '',
			'early_until'     => '',
			'discount_pair'   => '',
			'status'          => Term_Service::STATUS_DRAFT,
			'season_label_cs' => 'Jaro 2026',
			'season_label_en' => 'Spring 2026',
			'note_public_cs'  => '',
			'note_public_en'  => '',
		),
		// Open, with a pair discount.
		array(
			'course_title_cs' => 'Bachata pro mírně pokročilé',
			'location_name'   => 'Terasa 67 (Křižíkův pavilon B)',
			'type'            => Term_Service::TYPE_COURSE,
			'weekday'         => '2', // Tuesday.
			'start_time'      => '19:30',
			'end_time'        => '20:30',
			'date_from'       => '2025-09-02',
			'date_to'         => '2025-12-16',
			'instructor'      => 'Ana Kováčová',
			'capacity'        => '18',
			'price'           => '2600',
			'discount_early'  => '',
			'early_until'     => '',
			'discount_pair'   => '200',
			'status'          => Term_Service::STATUS_OPEN,
			'season_label_cs' => 'Podzim 2025',
			'season_label_en' => 'Autumn 2025',
			'note_public_cs'  => '',
			'note_public_en'  => '',
		),
		// Closed and at low capacity.
		array(
			'course_title_cs' => 'Dětský tanec',
			'location_name'   => 'Terasa Smíchov',
			'type'            => Term_Service::TYPE_COURSE,
			'weekday'         => '6', // Saturday.
			'start_time'      => '10:00',
			'end_time'        => '11:00',
			'date_from'       => '2025-09-06',
			'date_to'         => '2025-12-13',
			'instructor'      => 'Petra Nováková',
			'capacity'        => '6',
			'price'           => '1800',
			'discount_early'  => '',
			'early_until'     => '',
			'discount_pair'   => '',
			'status'          => Term_Service::STATUS_CLOSED,
			'season_label_cs' => 'Podzim 2025',
			'season_label_en' => 'Autumn 2025',
			'note_public_cs'  => 'Kapacita naplněna, čekejte na jarní běh.',
			'note_public_en'  => 'Full for this season — spring intake opens soon.',
		),
		// Workshop: a single lesson, date_from = date_to.
		array(
			'course_title_cs' => 'Dámský styling',
			'location_name'   => 'NYX Hotel Prague',
			'type'            => Term_Service::TYPE_WORKSHOP,
			'weekday'         => '6', // Ignored by the generator for a workshop, still stored for display.
			'start_time'      => '10:00',
			'end_time'        => '16:00',
			'date_from'       => '2025-11-15',
			'date_to'         => '2025-11-15',
			'instructor'      => 'Ruben García',
			'capacity'        => '',
			'price'           => '900',
			'discount_early'  => '',
			'early_until'     => '',
			'discount_pair'   => '',
			'status'          => Term_Service::STATUS_OPEN,
			'season_label_cs' => 'Zimní workshop 2025',
			'season_label_en' => 'Winter Workshop 2025',
			'note_public_cs'  => 'Jednorázová dílna, není potřeba partner.',
			'note_public_en'  => 'One-off workshop, no partner needed.',
		),
		// Cancelled.
		array(
			'course_title_cs' => 'Bachata pro mírně pokročilé',
			'location_name'   => 'Terasa 67 (Křižíkův pavilon B)',
			'type'            => Term_Service::TYPE_COURSE,
			'weekday'         => '4', // Thursday.
			'start_time'      => '18:00',
			'end_time'        => '19:00',
			'date_from'       => '2025-06-05',
			'date_to'         => '2025-08-28',
			'instructor'      => 'Ana Kováčová',
			'capacity'        => '16',
			'price'           => '2600',
			'discount_early'  => '',
			'early_until'     => '',
			'discount_pair'   => '',
			'status'          => Term_Service::STATUS_CANCELLED,
			'season_label_cs' => 'Léto 2025',
			'season_label_en' => 'Summer 2025',
			'note_public_cs'  => 'Zrušeno pro nízký zájem.',
			'note_public_en'  => 'Cancelled due to low interest.',
		),
		// Open, low capacity (M08: gives the enrollment fixtures a term that
		// can genuinely go over capacity, and doubles as a child-participant
		// fixture — kids' course, parents enroll more than one child).
		array(
			'course_title_cs' => 'Dětský tanec',
			'location_name'   => 'Terasa Smíchov',
			'type'            => Term_Service::TYPE_COURSE,
			'weekday'         => '6', // Saturday.
			'start_time'      => '09:00',
			'end_time'        => '10:00',
			'date_from'       => '2026-01-10',
			'date_to'         => '2026-04-25',
			'instructor'      => 'Petra Nováková',
			'capacity'        => '2',
			'price'           => '1800',
			'discount_early'  => '',
			'early_until'     => '',
			'discount_pair'   => '',
			'status'          => Term_Service::STATUS_OPEN,
			'season_label_cs' => 'Zima 2026',
			'season_label_en' => 'Winter 2026',
			'note_public_cs'  => '',
			'note_public_en'  => '',
		),
	);

	/**
	 * The three auth shortcode pages (M07), CS + EN. `slug_cs`/`slug_en` are
	 * matched against `post_name` for idempotency, the same natural-key idea
	 * as `find_course_by_title()`.
	 *
	 * @var array<int, array{which: string, slug_cs: string, title_cs: string, slug_en: string, title_en: string, shortcode: string}>
	 */
	const PAGES = array(
		array(
			'which'     => \RubenDance\Front\Pages::LOGIN,
			'slug_cs'   => 'prihlaseni',
			'title_cs'  => 'Přihlášení',
			'slug_en'   => 'login',
			'title_en'  => 'Log in',
			'shortcode' => '[rd_login]',
		),
		array(
			'which'     => \RubenDance\Front\Pages::REGISTER,
			'slug_cs'   => 'registrace',
			'title_cs'  => 'Registrace',
			'slug_en'   => 'register',
			'title_en'  => 'Register',
			'shortcode' => '[rd_register]',
		),
		array(
			'which'     => \RubenDance\Front\Pages::LOST_PASSWORD,
			'slug_cs'   => 'zapomenute-heslo',
			'title_cs'  => 'Zapomenuté heslo',
			'slug_en'   => 'lost-password',
			'title_en'  => 'Lost password',
			'shortcode' => '[rd_lost_password]',
		),
		// M08: the catalog and enrollment-form pages. `which` is a plain
		// string (matching `Front\Catalog_Page::PAGE_KEY`/`Front\Enroll_Page::PAGE_KEY`)
		// rather than a `Front\Pages::` constant — `Pages::set()`/`url()`
		// already accept an arbitrary key, so M08 never needs to touch M07's
		// `Pages` class.
		array(
			'which'     => 'catalog',
			'slug_cs'   => 'kurzy',
			'title_cs'  => 'Kurzy',
			'slug_en'   => 'courses',
			'title_en'  => 'Courses',
			'shortcode' => '[rd_catalog]',
		),
		array(
			'which'     => 'enroll',
			'slug_cs'   => 'prihlaska',
			'title_cs'  => 'Přihláška',
			'slug_en'   => 'enroll',
			'title_en'  => 'Enroll',
			'shortcode' => '[rd_enroll]',
		),
		// M09: the customer account page. `which` matches
		// `Front\Account_Page::PAGE_KEY` for the same "no change to M07's
		// Pages class" reason as `catalog`/`enroll` above.
		array(
			'which'     => 'account',
			'slug_cs'   => 'muj-ucet',
			'title_cs'  => 'Můj účet',
			'slug_en'   => 'my-account',
			'title_en'  => 'My account',
			'shortcode' => '[rd_account]',
		),
		// M10: the public calendar page. `which` matches
		// `Front\Calendar_Page::PAGE_KEY` for the same "no change to M07's
		// Pages class" reason as `catalog`/`enroll`/`account` above.
		array(
			'which'     => 'calendar',
			'slug_cs'   => 'kalendar',
			'title_cs'  => 'Kalendář',
			'slug_en'   => 'calendar',
			'title_en'  => 'Calendar',
			'shortcode' => '[rd_calendar]',
		),
	);

	/**
	 * Placeholder privacy policy + Terms & Conditions pages (M15/§6.1, §6.3:
	 * "placeholder pages ... CS/EN, structured lorem — real texts are the
	 * lawyer's"). Structured section headings so the *shape* required by
	 * spec §6.1 (identification, purposes/legal basis, retention, rights,
	 * processors) and §6.3 (identification, price/payment/cancellation
	 * terms, the § 1837(j) withdrawal-exemption statement, complaints) is
	 * already in place for the lawyer to fill in real text pre-launch, rather
	 * than a blank page appearing the moment `get_privacy_policy_url()`/
	 * `Front\Pages::url( Front\Pages::TERMS, ... )` starts resolving to it.
	 *
	 * @var array<int, array{which: string, slug_cs: string, title_cs: string, content_cs: string, slug_en: string, title_en: string, content_en: string, set_as_core_privacy_page: bool}>
	 */
	const LEGAL_PAGES = array(
		array(
			'which'                    => \RubenDance\Front\Pages::PRIVACY_POLICY,
			'slug_cs'                  => 'zasady-ochrany-osobnich-udaju',
			'title_cs'                 => 'Zásady ochrany osobních údajů',
			'content_cs'               => <<<'HTML'
<p><em>PLACEHOLDER — text připraví a před spuštěním provozu schválí právník. Struktura níže odpovídá bodům vyžadovaným specifikací (§6.1).</em></p>
<h2>Kdo jsme</h2>
<p>[Název školy], IČO [doplnit], se sídlem [doplnit adresu], je správcem osobních údajů zpracovávaných v souvislosti s provozem tohoto webu.</p>
<h2>Jaké údaje zpracováváme a proč</h2>
<p>Jméno, e-mail, telefon a heslo (pro vedení účtu a přihlášek), údaje o přihláškách a platbách (pro poskytnutí kurzu a účetnictví), volitelně jméno partnera (pro organizaci kurzu) a záznam o odeslaných e-mailech (metadata a předmět, nikdy celý obsah).</p>
<h2>Právní základ zpracování</h2>
<p>Plnění smlouvy (vedení účtu, přihlášky), plnění právních povinností (účetní záznamy) a oprávněný zájem (organizační poznámky, e-mailový log). Marketingové e-maily pouze na základě uděleného souhlasu.</p>
<h2>Doba uchovávání</h2>
<p>Neaktivní zákaznické účty jsou anonymizovány po [N] letech, e-mailový log je mazán po 1 roce, nezaplacené zrušené přihlášky po 1 roce.</p>
<h2>Vaše práva</h2>
<p>Máte právo na přístup ke svým údajům, jejich opravu, výmaz (anonymizaci) a přenositelnost. Žádost můžete podat prostřednictvím nástrojů WordPress nebo kontaktováním správce.</p>
<h2>Zpracovatelé</h2>
<p>[Doplnit: poskytovatel hostingu, e-mailová služba, případně Cloudflare], každý na základě zpracovatelské smlouvy.</p>
<h2>Cookies</h2>
<p>Web používá pouze technické cookies nezbytné pro přihlášení a volbu jazyka — viz [odkaz na cookie audit].</p>
<h2>Kontakt</h2>
<p>[Doplnit kontaktní e-mail/adresu pro dotazy ohledně ochrany osobních údajů.]</p>
HTML
			,
			'slug_en'                  => 'privacy-policy',
			'title_en'                 => 'Privacy Policy',
			'content_en'               => <<<'HTML'
<p><em>PLACEHOLDER — real text to be drafted and legally reviewed before launch. The structure below mirrors the points required by the spec (§6.1).</em></p>
<h2>Who we are</h2>
<p>[School name], company ID [fill in], registered at [fill in address], is the controller of the personal data processed through this website.</p>
<h2>What data we process and why</h2>
<p>Name, email, phone and password (account and enrollment handling), enrollment and payment records (course delivery and accounting), an optional partner name (course organization) and a log of emails sent (metadata and subject only, never full bodies).</p>
<h2>Legal basis</h2>
<p>Contract performance (account, enrollments), legal obligation (accounting records) and legitimate interest (organizational notes, email log). Marketing emails only with your explicit consent.</p>
<h2>Retention</h2>
<p>Inactive customer accounts are anonymized after [N] years, the email log is purged after 1 year, unpaid cancelled enrollments after 1 year.</p>
<h2>Your rights</h2>
<p>You have the right to access, correct, erase (anonymize) and receive a copy of your data. Requests can be made through WordPress's own tools or by contacting the controller directly.</p>
<h2>Processors</h2>
<p>[Fill in: hosting provider, transactional email service, Cloudflare if used], each under a data processing agreement.</p>
<h2>Cookies</h2>
<p>The site uses only technical cookies strictly necessary for login and language selection — see [link to cookie audit].</p>
<h2>Contact</h2>
<p>[Fill in a contact email/address for privacy-related questions.]</p>
HTML
			,
			'set_as_core_privacy_page' => true,
		),
		array(
			'which'                    => \RubenDance\Front\Pages::TERMS,
			'slug_cs'                  => 'obchodni-podminky',
			'title_cs'                 => 'Obchodní podmínky',
			'content_cs'               => <<<'HTML'
<p><em>PLACEHOLDER — text připraví a před spuštěním provozu schválí právník. Struktura níže odpovídá bodům vyžadovaným specifikací (§6.3).</em></p>
<h2>Identifikace poskytovatele</h2>
<p>[Název školy], IČO [doplnit], se sídlem [doplnit adresu], kontakt: [doplnit e-mail/telefon].</p>
<h2>Popis služby a cena</h2>
<p>Poskytovatel nabízí taneční kurzy a workshopy. Cena každého kurzu, včetně případných slev, je uvedena u dané přihlášky před jejím odesláním.</p>
<h2>Platební a storno podmínky</h2>
<p>Platba probíhá bankovním převodem (variabilní symbol) nebo hotově, se splatností uvedenou v potvrzení přihlášky. [Doplnit storno podmínky dle rozhodnutí provozovatele.]</p>
<h2>Odstoupení od smlouvy</h2>
<p>V souladu s § 1837 písm. j) občanského zákoníku se právo na odstoupení od smlouvy do 14 dnů nevztahuje na služby volného času poskytované v určeném termínu, mezi které taneční kurzy s pevným rozvrhem spadají. [Toto ustanovení je třeba potvrdit právníkem.]</p>
<h2>Reklamace</h2>
<p>Případné reklamace vyřizuje poskytovatel na kontaktních údajích uvedených výše.</p>
<h2>Ochrana osobních údajů</h2>
<p>Zpracování osobních údajů se řídí [zásadami ochrany osobních údajů].</p>
<h2>Závěrečná ustanovení</h2>
<p>[Doplnit dle potřeby.]</p>
HTML
			,
			'slug_en'                  => 'terms-and-conditions',
			'title_en'                 => 'Terms & Conditions',
			'content_en'               => <<<'HTML'
<p><em>PLACEHOLDER — real text to be drafted and legally reviewed before launch. The structure below mirrors the points required by the spec (§6.3).</em></p>
<h2>Provider identification</h2>
<p>[School name], company ID [fill in], registered at [fill in address], contact: [fill in email/phone].</p>
<h2>Service description and price</h2>
<p>The provider offers dance courses and workshops. The price of each course, including any applicable discounts, is shown on the enrollment form before submission.</p>
<h2>Payment and cancellation terms</h2>
<p>Payment is made by bank transfer (variable symbol) or cash, due by the date shown on the enrollment confirmation. [Fill in cancellation terms as decided by the owners.]</p>
<h2>Right of withdrawal</h2>
<p>Under § 1837(j) of the Czech Civil Code, the 14-day right of withdrawal from a distance contract does not apply to leisure-time services provided on a specific date, which fixed-schedule dance courses fall under. [This clause requires legal confirmation.]</p>
<h2>Complaints</h2>
<p>Complaints are handled by the provider using the contact details above.</p>
<h2>Personal data</h2>
<p>Personal data processing is governed by the [privacy policy].</p>
<h2>Final provisions</h2>
<p>[Fill in as needed.]</p>
HTML
			,
			'set_as_core_privacy_page' => false,
		),
	);

	/**
	 * The voucher info page (M16/F17/§4.6: "a voucher info page (normal WP
	 * content, CS + EN) describing the offer, with a short inquiry form").
	 * Shaped like `self::LEGAL_PAGES` (real body content per language, not a
	 * bare shortcode) but seeded by its own `seed_voucher_page()` method
	 * rather than added to that array — a voucher page also carries the
	 * `[rd_voucher_inquiry]` shortcode inside its content, which the legal
	 * pages never do.
	 *
	 * @var array<int, array{which: string, slug_cs: string, title_cs: string, content_cs: string, slug_en: string, title_en: string, content_en: string}>
	 */
	const VOUCHER_PAGE = array(
		array(
			'which'      => 'voucher',
			'slug_cs'    => 'darkove-poukazy',
			'title_cs'   => 'Dárkové poukazy',
			'content_cs' => <<<'HTML'
<p>Hledáte originální dárek pro tanečního nadšence? Dárkový poukaz Ruben Dance lze uplatnit na jakýkoli náš kurz nebo workshop — stačí při přihlášení uvést číslo poukazu do poznámky, my platbu spárujeme a přihlášku potvrdíme.</p>
<p>Poukazy vystavujeme na libovolnou částku i na konkrétní kurz. Napište nám prosím pár slov o tom, o jaký poukaz máte zájem (částka, kurz, termín předání) a ozveme se s dalšími kroky.</p>
[rd_voucher_inquiry]
HTML
			,
			'slug_en'    => 'gift-vouchers',
			'title_en'   => 'Gift Vouchers',
			'content_en' => <<<'HTML'
<p>Looking for an original gift for a dance enthusiast? A Ruben Dance gift voucher can be redeemed against any of our courses or workshops — just mention the voucher in the note when enrolling, and we'll match the payment and confirm the enrollment.</p>
<p>Vouchers can be issued for any amount or for a specific course. Send us a few words about the voucher you're interested in (amount, course, when you'd like to receive it) and we'll get back to you with next steps.</p>
[rd_voucher_inquiry]
HTML
			,
		),
	);

	/**
	 * Five verified customers (M07: "5 verified customers with locales/
	 * consents varied") — pre-verified via `Registration_Service::register_pre_verified()`
	 * so admin/enrollment milestone fixtures can use them immediately without
	 * an email round-trip on every `wp rd seed` run.
	 *
	 * @var array<int, array{first_name: string, last_name: string, email: string, phone: string, password: string, locale: string, marketing_consent: bool}>
	 */
	const CUSTOMERS = array(
		array(
			'first_name'        => 'Jana',
			'last_name'         => 'Nováková',
			'email'             => 'jana.novakova@example.com',
			'phone'             => '+420 601 111 222',
			'password'          => 'RubenDance2025!',
			'locale'            => Lang::CS,
			'marketing_consent' => true,
		),
		array(
			'first_name'        => 'Petr',
			'last_name'         => 'Svoboda',
			'email'             => 'petr.svoboda@example.com',
			'phone'             => '+420 602 222 333',
			'password'          => 'RubenDance2025!',
			'locale'            => Lang::CS,
			'marketing_consent' => false,
		),
		array(
			'first_name'        => 'Eva',
			'last_name'         => 'Dvořáková',
			'email'             => 'eva.dvorakova@example.com',
			'phone'             => '+420 603 333 444',
			'password'          => 'RubenDance2025!',
			'locale'            => Lang::CS,
			'marketing_consent' => true,
		),
		array(
			'first_name'        => 'John',
			'last_name'         => 'Smith',
			'email'             => 'john.smith@example.com',
			'phone'             => '+44 7700 900111',
			'password'          => 'RubenDance2025!',
			'locale'            => Lang::EN,
			'marketing_consent' => false,
		),
		array(
			'first_name'        => 'Emily',
			'last_name'         => 'Clark',
			'email'             => 'emily.clark@example.com',
			'phone'             => '+44 7700 900222',
			'password'          => 'RubenDance2025!',
			'locale'            => Lang::EN,
			'marketing_consent' => true,
		),
	);

	/**
	 * ~15 enrollments spanning paid/unpaid/cancelled/over-capacity/
	 * child-participant scenarios (M08: "this becomes the admin milestones'
	 * fixture"). `term_season_label_cs` pairs with `course_title_cs` to
	 * locate the term via `Course_Term_Repository::find_by_course_and_season()`,
	 * the same natural-key idea `seed_terms()` uses. Processed in this exact
	 * order — the low-capacity "Zima 2026" term (see `TERMS`) only tips over
	 * its capacity of 2 from the third enrollment onward, so entries 10-14
	 * are deliberately sequential to produce a realistic
	 * over_capacity/paid/cancelled mix on that one term.
	 *
	 * @var array<int, array{email: string, course_title_cs: string, season_label_cs: string, participant_name: string, role: string, partner_name: string, mark_paid: bool, cancel: bool}>
	 */
	const ENROLLMENTS = array(
		array(
			'email'            => 'jana.novakova@example.com',
			'course_title_cs'  => 'Salsa pro začátečníky',
			'season_label_cs'  => 'Podzim 2025',
			'participant_name' => '',
			'role'             => 'solo',
			'partner_name'     => '',
			'mark_paid'        => false,
			'cancel'           => false,
		),
		array(
			'email'            => 'jana.novakova@example.com',
			'course_title_cs'  => 'Salsa pro začátečníky',
			'season_label_cs'  => 'Podzim 2025',
			'participant_name' => 'Klára Nováková',
			'role'             => 'solo',
			'partner_name'     => '',
			'mark_paid'        => false,
			'cancel'           => false,
		),
		array(
			'email'            => 'petr.svoboda@example.com',
			'course_title_cs'  => 'Bachata pro mírně pokročilé',
			'season_label_cs'  => 'Podzim 2025',
			'participant_name' => '',
			'role'             => 'leader',
			'partner_name'     => 'Lucie Svobodová',
			'mark_paid'        => false,
			'cancel'           => false,
		),
		array(
			'email'            => 'eva.dvorakova@example.com',
			'course_title_cs'  => 'Bachata pro mírně pokročilé',
			'season_label_cs'  => 'Podzim 2025',
			'participant_name' => '',
			'role'             => 'follower',
			'partner_name'     => 'Petr Svoboda',
			'mark_paid'        => true,
			'cancel'           => false,
		),
		array(
			'email'            => 'eva.dvorakova@example.com',
			'course_title_cs'  => 'Salsa pro začátečníky',
			'season_label_cs'  => 'Podzim 2025',
			'participant_name' => '',
			'role'             => 'solo',
			'partner_name'     => '',
			'mark_paid'        => false,
			'cancel'           => true,
		),
		array(
			'email'            => 'john.smith@example.com',
			'course_title_cs'  => 'Salsa pro začátečníky',
			'season_label_cs'  => 'Podzim 2025',
			'participant_name' => '',
			'role'             => 'solo',
			'partner_name'     => '',
			'mark_paid'        => true,
			'cancel'           => false,
		),
		array(
			'email'            => 'john.smith@example.com',
			'course_title_cs'  => 'Dámský styling',
			'season_label_cs'  => 'Zimní workshop 2025',
			'participant_name' => '',
			'role'             => 'solo',
			'partner_name'     => '',
			'mark_paid'        => false,
			'cancel'           => false,
		),
		array(
			'email'            => 'emily.clark@example.com',
			'course_title_cs'  => 'Dámský styling',
			'season_label_cs'  => 'Zimní workshop 2025',
			'participant_name' => '',
			'role'             => 'solo',
			'partner_name'     => '',
			'mark_paid'        => true,
			'cancel'           => false,
		),
		array(
			'email'            => 'emily.clark@example.com',
			'course_title_cs'  => 'Bachata pro mírně pokročilé',
			'season_label_cs'  => 'Podzim 2025',
			'participant_name' => '',
			'role'             => 'leader',
			'partner_name'     => '',
			'mark_paid'        => false,
			'cancel'           => false,
		),
		array(
			'email'            => 'jana.novakova@example.com',
			'course_title_cs'  => 'Dětský tanec',
			'season_label_cs'  => 'Zima 2026',
			'participant_name' => 'Tomáš Novák',
			'role'             => 'solo',
			'partner_name'     => '',
			'mark_paid'        => false,
			'cancel'           => false,
		),
		array(
			'email'            => 'jana.novakova@example.com',
			'course_title_cs'  => 'Dětský tanec',
			'season_label_cs'  => 'Zima 2026',
			'participant_name' => 'Anna Nováková',
			'role'             => 'solo',
			'partner_name'     => '',
			'mark_paid'        => false,
			'cancel'           => false,
		),
		array(
			'email'            => 'petr.svoboda@example.com',
			'course_title_cs'  => 'Dětský tanec',
			'season_label_cs'  => 'Zima 2026',
			'participant_name' => 'David Svoboda',
			'role'             => 'solo',
			'partner_name'     => '',
			'mark_paid'        => false,
			'cancel'           => false,
		),
		array(
			'email'            => 'eva.dvorakova@example.com',
			'course_title_cs'  => 'Dětský tanec',
			'season_label_cs'  => 'Zima 2026',
			'participant_name' => 'Petra Dvořáková',
			'role'             => 'solo',
			'partner_name'     => '',
			'mark_paid'        => false,
			'cancel'           => true,
		),
		array(
			'email'            => 'john.smith@example.com',
			'course_title_cs'  => 'Dětský tanec',
			'season_label_cs'  => 'Zima 2026',
			'participant_name' => 'Oliver Smith',
			'role'             => 'solo',
			'partner_name'     => '',
			'mark_paid'        => true,
			'cancel'           => false,
		),
		array(
			'email'            => 'emily.clark@example.com',
			'course_title_cs'  => 'Salsa pro začátečníky',
			'season_label_cs'  => 'Podzim 2025',
			'participant_name' => '',
			'role'             => 'solo',
			'partner_name'     => '',
			'mark_paid'        => false,
			'cancel'           => false,
		),
	);

	/**
	 * Seed the database with development/test fixture data.
	 *
	 * ## EXAMPLES
	 *
	 *     wp rd seed
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments (unused).
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args ); // Required by the WP-CLI callable signature; unused for now.

		$locations_created    = $this->seed_locations();
		$courses_created      = $this->seed_courses();
		$terms_created        = $this->seed_terms();
		$pages_created        = $this->seed_pages();
		$legal_pages_created  = $this->seed_legal_pages();
		$voucher_page_created = $this->seed_voucher_page();
		$customers_created    = $this->seed_customers();
		$enrollments_created  = $this->seed_enrollments();

		\WP_CLI::success(
			sprintf(
				'ruben-dance: seeded (%d location(s), %d course(s), %d term(s), %d page(s), %d legal page(s), %d voucher page(s), %d customer(s), %d enrollment(s) created).',
				$locations_created,
				$courses_created,
				$terms_created,
				$pages_created,
				$legal_pages_created,
				$voucher_page_created,
				$customers_created,
				$enrollments_created
			)
		);
	}

	/**
	 * Insert the fixture locations, skipping any that already exist (matched
	 * by exact name) so repeated runs never create duplicates.
	 *
	 * @return int Number of locations actually created.
	 */
	private function seed_locations(): int {
		$repository = new Location_Repository();
		$created    = 0;

		foreach ( self::LOCATIONS as $location ) {
			if ( null !== $repository->find_by_name( $location['name'] ) ) {
				continue;
			}

			$repository->insert(
				array(
					'name'      => $location['name'],
					'address'   => $location['address'],
					'map_url'   => $location['map_url'],
					'is_active' => 1,
				)
			);

			++$created;
		}

		return $created;
	}

	/**
	 * Insert the fixture courses, skipping any whose Czech title already
	 * exists (the canonical post, per spec §5 Multilingual: "the term's
	 * `course_id` always points to the Czech course post") so repeated runs
	 * never create duplicates. When Polylang is active, each course also
	 * gets its English translation, linked via `pll_save_post_translations()`;
	 * when Polylang is absent, only the Czech post is created — seeding must
	 * degrade the same way the rest of the plugin does (see `Lang`).
	 *
	 * @return int Number of Czech course posts actually created.
	 */
	private function seed_courses(): int {
		$created = 0;

		foreach ( self::COURSES as $course ) {
			if ( null !== $this->find_course_by_title( $course['title_cs'] ) ) {
				continue;
			}

			$cs_id = wp_insert_post(
				array(
					'post_type'    => Post_Types::COURSE,
					'post_title'   => $course['title_cs'],
					'post_content' => $course['content_cs'],
					'post_status'  => 'publish',
				),
				true
			);

			if ( is_wp_error( $cs_id ) || ! $cs_id ) {
				continue;
			}

			$en_id = wp_insert_post(
				array(
					'post_type'    => Post_Types::COURSE,
					'post_title'   => $course['title_en'],
					'post_content' => $course['content_en'],
					'post_status'  => 'publish',
				),
				true
			);
			$en_id = is_wp_error( $en_id ) ? 0 : (int) $en_id;

			$style = $this->paired_term( Taxonomies::DANCE_STYLE, $course['style_cs'], $course['style_en'] );
			$level = $this->paired_term( Taxonomies::LEVEL, $course['level_cs'], $course['level_en'] );

			wp_set_object_terms( $cs_id, array( $style['cs'] ), Taxonomies::DANCE_STYLE );
			wp_set_object_terms( $cs_id, array( $level['cs'] ), Taxonomies::LEVEL );

			if ( function_exists( 'pll_set_post_language' ) ) {
				pll_set_post_language( $cs_id, Lang::CS );

				if ( $en_id ) {
					pll_set_post_language( $en_id, Lang::EN );
				}
			}

			if ( $en_id ) {
				wp_set_object_terms( $en_id, array( $style['en'] ), Taxonomies::DANCE_STYLE );
				wp_set_object_terms( $en_id, array( $level['en'] ), Taxonomies::LEVEL );

				if ( function_exists( 'pll_save_post_translations' ) ) {
					pll_save_post_translations(
						array(
							Lang::CS => $cs_id,
							Lang::EN => $en_id,
						)
					);
				}
			}

			++$created;
		}

		return $created;
	}

	/**
	 * Insert the fixture terms, skipping any that already exist (matched by
	 * course + season label, the same natural-key idea as
	 * `Location_Repository::find_by_name()`), so repeated runs never create
	 * duplicates. Goes through `Term_Service::validate()`/`create()` — the
	 * same code path a real admin form submission uses — rather than
	 * inserting rows directly, so lessons are generated for every seeded
	 * term exactly as they would be for a real one.
	 *
	 * @return int Number of terms actually created.
	 */
	private function seed_terms(): int {
		$term_repository     = new Course_Term_Repository();
		$location_repository = new Location_Repository();
		$service             = Term_Service::create_default();
		$created             = 0;

		foreach ( self::TERMS as $term ) {
			$course_id = $this->find_course_by_title( $term['course_title_cs'] );

			if ( null === $course_id ) {
				continue; // Defensive: the matching course should always have been seeded already.
			}

			if ( null !== $term_repository->find_by_course_and_season( $course_id, $term['season_label_cs'] ) ) {
				continue;
			}

			$location = $location_repository->find_by_name( $term['location_name'] );

			if ( null === $location ) {
				continue; // Defensive: the matching location should always have been seeded already.
			}

			$data = array(
				'course_id'       => $course_id,
				'location_id'     => (int) $location['id'],
				'type'            => $term['type'],
				'weekday'         => $term['weekday'],
				'start_time'      => $term['start_time'],
				'end_time'        => $term['end_time'],
				'date_from'       => $term['date_from'],
				'date_to'         => $term['date_to'],
				'instructor'      => $term['instructor'],
				'capacity'        => $term['capacity'],
				'price'           => $term['price'],
				'discount_early'  => $term['discount_early'],
				'early_until'     => $term['early_until'],
				'discount_pair'   => $term['discount_pair'],
				'status'          => $term['status'],
				'season_label_cs' => $term['season_label_cs'],
				'season_label_en' => $term['season_label_en'],
				'note_public_cs'  => $term['note_public_cs'],
				'note_public_en'  => $term['note_public_en'],
			);

			if ( array() !== $service->validate( $data ) ) {
				continue; // Defensive: fixture data is expected to always validate.
			}

			$service->create( $data );

			++$created;
		}

		return $created;
	}

	/**
	 * Insert the fixture auth pages (M07), skipping any whose slug already
	 * exists in that language so repeated runs never create duplicates.
	 * Registers each created page's ID with `Front\Pages` so the rest of the
	 * plugin (verification links, redirects between login/register/lost-
	 * password) can find them without searching post content.
	 *
	 * @return int Number of pages actually created.
	 */
	private function seed_pages(): int {
		$created = 0;

		foreach ( self::PAGES as $page ) {
			foreach (
				array(
					Lang::CS => array( $page['slug_cs'], $page['title_cs'] ),
					Lang::EN => array( $page['slug_en'], $page['title_en'] ),
				) as $lang => list( $slug, $title )
			) {
				$existing_id = $this->find_page_by_slug( $slug );

				if ( null !== $existing_id ) {
					\RubenDance\Front\Pages::set( $page['which'], $lang, $existing_id );
					continue;
				}

				$post_id = wp_insert_post(
					array(
						'post_type'    => 'page',
						'post_title'   => $title,
						'post_name'    => $slug,
						'post_content' => $page['shortcode'],
						'post_status'  => 'publish',
					),
					true
				);

				if ( is_wp_error( $post_id ) || ! $post_id ) {
					continue;
				}

				if ( function_exists( 'pll_set_post_language' ) ) {
					pll_set_post_language( $post_id, $lang );
				}

				\RubenDance\Front\Pages::set( $page['which'], $lang, (int) $post_id );

				++$created;
			}
		}

		if ( function_exists( 'pll_save_post_translations' ) ) {
			$map = get_option( \RubenDance\Front\Pages::OPTION, array() );

			foreach ( self::PAGES as $page ) {
				$pair = $map[ $page['which'] ] ?? array();

				if ( isset( $pair[ Lang::CS ], $pair[ Lang::EN ] ) ) {
					pll_save_post_translations(
						array(
							Lang::CS => (int) $pair[ Lang::CS ],
							Lang::EN => (int) $pair[ Lang::EN ],
						)
					);
				}
			}
		}

		return $created;
	}

	/**
	 * Insert the placeholder privacy policy + T&C pages (M15/§6.1, §6.3),
	 * skipping any whose slug already exists in that language — the same
	 * idempotency approach `seed_pages()` uses, kept as a separate method
	 * because these pages carry real (if placeholder) body content per
	 * language rather than one shared shortcode string. Also wires the
	 * seeded Czech privacy policy page into WordPress core's own
	 * `wp_page_for_privacy_policy` option (only if unset, never overwriting a
	 * site operator's manual choice), which is what makes core's
	 * `get_privacy_policy_url()` — already called by the registration/
	 * enrollment forms — resolve to something real.
	 *
	 * @return int Number of pages actually created.
	 */
	private function seed_legal_pages(): int {
		$created = 0;

		$registered = get_option( \RubenDance\Front\Pages::OPTION, array() );

		foreach ( self::LEGAL_PAGES as $page ) {
			$cs_id = null;

			foreach (
				array(
					Lang::CS => array( $page['slug_cs'], $page['title_cs'], $page['content_cs'] ),
					Lang::EN => array( $page['slug_en'], $page['title_en'], $page['content_en'] ),
				) as $lang => list( $slug, $title, $content )
			) {
				// Idempotency check #1: the plugin's *own* record of which
				// page ID it already created for this which/lang pair,
				// regardless of that page's current slug. This must come
				// first, ahead of `find_page_by_slug()` — if WordPress had to
				// auto-suffix the slug on a previous run (e.g. `privacy-
				// policy` was already taken by the stock draft page `wp core
				// install` creates on every fresh site), the live page no
				// longer has the exact slug below, and a slug-only lookup
				// would never find it again — silently creating a fresh
				// duplicate (and a fresh suffix clash) on every single `wp rd
				// seed` re-run.
				$registered_id = (int) ( $registered[ $page['which'] ][ $lang ] ?? 0 );

				if ( $registered_id > 0 && 'publish' === get_post_status( $registered_id ) ) {
					if ( Lang::CS === $lang ) {
						$cs_id = $registered_id;
					}

					continue;
				}

				// Idempotency check #2: a published page with the exact
				// slug already exists — the bootstrapping case for a site
				// that has never run `wp rd seed` before but already has a
				// matching page (mirrors `seed_pages()`'s own approach).
				$existing_id = $this->find_page_by_slug( $slug );

				if ( null !== $existing_id ) {
					\RubenDance\Front\Pages::set( $page['which'], $lang, $existing_id );

					if ( Lang::CS === $lang ) {
						$cs_id = $existing_id;
					}

					continue;
				}

				$post_id = wp_insert_post(
					array(
						'post_type'    => 'page',
						'post_title'   => $title,
						'post_name'    => $slug,
						'post_content' => $content,
						'post_status'  => 'publish',
					),
					true
				);

				if ( is_wp_error( $post_id ) || ! $post_id ) {
					continue;
				}

				if ( function_exists( 'pll_set_post_language' ) ) {
					pll_set_post_language( $post_id, $lang );
				}

				\RubenDance\Front\Pages::set( $page['which'], $lang, (int) $post_id );

				if ( Lang::CS === $lang ) {
					$cs_id = (int) $post_id;
				}

				++$created;
			}

			if ( $page['set_as_core_privacy_page'] && null !== $cs_id ) {
				$current_privacy_page_id = (int) get_option( 'wp_page_for_privacy_policy', 0 );

				// Overwrite when unset, *or* when it still points at the
				// unedited stock draft `wp core install` creates on every
				// fresh site (see `find_page_by_slug()`'s comment) — but
				// never when it already points at a page someone actually
				// published, which means an operator deliberately chose it.
				if ( 0 === $current_privacy_page_id || 'publish' !== get_post_status( $current_privacy_page_id ) ) {
					update_option( 'wp_page_for_privacy_policy', $cs_id );
				}
			}
		}

		if ( function_exists( 'pll_save_post_translations' ) ) {
			$map = get_option( \RubenDance\Front\Pages::OPTION, array() );

			foreach ( self::LEGAL_PAGES as $page ) {
				$pair = $map[ $page['which'] ] ?? array();

				if ( isset( $pair[ Lang::CS ], $pair[ Lang::EN ] ) ) {
					pll_save_post_translations(
						array(
							Lang::CS => (int) $pair[ Lang::CS ],
							Lang::EN => (int) $pair[ Lang::EN ],
						)
					);
				}
			}
		}

		return $created;
	}

	/**
	 * Insert the voucher info page (M16/F17), skipping any whose slug already
	 * exists in that language — the same two-step idempotency
	 * (`Front\Pages`' own record first, then a slug lookup) `seed_legal_pages()`
	 * uses, so a previous run's auto-suffixed slug is never duplicated.
	 *
	 * @return int Number of pages actually created.
	 */
	private function seed_voucher_page(): int {
		$created = 0;

		$registered = get_option( \RubenDance\Front\Pages::OPTION, array() );

		foreach ( self::VOUCHER_PAGE as $page ) {
			foreach (
				array(
					Lang::CS => array( $page['slug_cs'], $page['title_cs'], $page['content_cs'] ),
					Lang::EN => array( $page['slug_en'], $page['title_en'], $page['content_en'] ),
				) as $lang => list( $slug, $title, $content )
			) {
				$registered_id = (int) ( $registered[ $page['which'] ][ $lang ] ?? 0 );

				if ( $registered_id > 0 && 'publish' === get_post_status( $registered_id ) ) {
					continue;
				}

				$existing_id = $this->find_page_by_slug( $slug );

				if ( null !== $existing_id ) {
					\RubenDance\Front\Pages::set( $page['which'], $lang, $existing_id );
					continue;
				}

				$post_id = wp_insert_post(
					array(
						'post_type'    => 'page',
						'post_title'   => $title,
						'post_name'    => $slug,
						'post_content' => $content,
						'post_status'  => 'publish',
					),
					true
				);

				if ( is_wp_error( $post_id ) || ! $post_id ) {
					continue;
				}

				if ( function_exists( 'pll_set_post_language' ) ) {
					pll_set_post_language( $post_id, $lang );
				}

				\RubenDance\Front\Pages::set( $page['which'], $lang, (int) $post_id );

				++$created;
			}
		}

		if ( function_exists( 'pll_save_post_translations' ) ) {
			$map = get_option( \RubenDance\Front\Pages::OPTION, array() );

			foreach ( self::VOUCHER_PAGE as $page ) {
				$pair = $map[ $page['which'] ] ?? array();

				if ( isset( $pair[ Lang::CS ], $pair[ Lang::EN ] ) ) {
					pll_save_post_translations(
						array(
							Lang::CS => (int) $pair[ Lang::CS ],
							Lang::EN => (int) $pair[ Lang::EN ],
						)
					);
				}
			}
		}

		return $created;
	}

	/**
	 * Insert the fixture customers, skipping any whose email already exists
	 * (matched via WordPress' own `email_exists()`, the users-table
	 * equivalent of `find_by_name()`), so repeated runs never create
	 * duplicates. Goes through `Registration_Service::register_pre_verified()`
	 * — the same field-mapping/meta-capture logic a real (verified)
	 * registration uses — rather than inserting rows directly.
	 *
	 * @return int Number of customers actually created.
	 */
	private function seed_customers(): int {
		$service = Registration_Service::create_default();
		$created = 0;

		foreach ( self::CUSTOMERS as $customer ) {
			if ( false !== email_exists( $customer['email'] ) ) {
				continue;
			}

			$service->register_pre_verified( $customer );

			++$created;
		}

		return $created;
	}

	/**
	 * Insert the fixture enrollments (M08: "this becomes the admin
	 * milestones' fixture"). Goes through `Enrollment_Service::validate()`/
	 * `create()` — the same code path a real public submission uses — so
	 * price/discount_note/variable_symbol/due_date are computed exactly as
	 * they would be for a real enrollment, never hand-rolled here.
	 * Idempotent via `Enrollment_Service::create()`'s own duplicate-key
	 * guard (spec §3.3: `term_id`/`user_id`/`participant_name` unique key):
	 * a repeated `wp rd seed` run hits `Duplicate_Enrollment_Exception` for
	 * every row already inserted and simply skips it, the same as every
	 * other `Duplicate_Enrollment_Exception` catch site in the plugin.
	 *
	 * @return int Number of enrollments actually created.
	 */
	private function seed_enrollments(): int {
		$term_repository = new Course_Term_Repository();
		$service         = Enrollment_Service::create_default();
		$admin_id        = $this->find_an_admin_user_id();
		$created         = 0;

		foreach ( self::ENROLLMENTS as $fixture ) {
			$user = get_user_by( 'email', $fixture['email'] );

			if ( false === $user ) {
				continue; // Defensive: the matching customer should always have been seeded already.
			}

			$course_id = $this->find_course_by_title( $fixture['course_title_cs'] );

			if ( null === $course_id ) {
				continue; // Defensive: the matching course should always have been seeded already.
			}

			$term = $term_repository->find_by_course_and_season( $course_id, $fixture['season_label_cs'] );

			if ( null === $term ) {
				continue; // Defensive: the matching term should always have been seeded already.
			}

			$data = array(
				'term_id'          => (int) $term['id'],
				'user_id'          => $user->ID,
				'participant_name' => $fixture['participant_name'],
				'role'             => $fixture['role'],
				'partner_name'     => $fixture['partner_name'],
				'payment_method'   => Enrollment_Service::PAYMENT_BANK_TRANSFER,
			);

			if ( array() !== $service->validate( $data ) ) {
				continue; // Defensive: fixture data is expected to always validate.
			}

			try {
				$id = $service->create( $data );
			} catch ( Duplicate_Enrollment_Exception $e ) {
				continue; // Already seeded on an earlier run.
			}

			if ( $fixture['mark_paid'] ) {
				$service->mark_paid( $id, $admin_id );
			} elseif ( $fixture['cancel'] ) {
				$service->cancel( $id );
			}

			++$created;
		}

		return $created;
	}

	/**
	 * An administrator user ID, for `paid_marked_by` on the seeded "paid"
	 * enrollments. Falls back to `1` (the default wp-env admin account) if,
	 * unusually, no administrator exists yet — this is fixture data for a
	 * dev/test site, not a production safety concern.
	 *
	 * @return int
	 */
	private function find_an_admin_user_id(): int {
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			)
		);

		return array() !== $admins ? (int) $admins[0] : 1;
	}

	/**
	 * Find a published page by its exact slug (`post_name`). Used for
	 * idempotency, the same reasoning as `find_course_by_title()`.
	 *
	 * @param string $slug Exact `post_name` to match.
	 * @return int|null Post ID, or null if not found.
	 */
	private function find_page_by_slug( string $slug ): ?int {
		global $wpdb;

		// `post_status = 'publish'` (not merely "!= trash") matters as of
		// M15: WordPress core itself auto-creates a draft page slugged
		// `privacy-policy` on every fresh install (its own GDPR onboarding
		// nudge, wired to `wp_page_for_privacy_policy`) — matching on any
		// non-trashed status would silently adopt that unrelated stock draft
		// as if it were our own seeded page, per the doc comment above this
		// method already promised ("a published page") but the query never
		// actually enforced.
		$id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_name = %s AND post_status = 'publish' LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$slug
			)
		);

		return null === $id ? null : (int) $id;
	}

	/**
	 * Find a course by its exact (Czech) title. Used for idempotency, the
	 * same way `Location_Repository::find_by_name()` is: `rd_course` is a
	 * core post type, not a `wp_rd_*` table, so this queries `$wpdb->posts`
	 * directly rather than through a repository (repositories are only for
	 * the plugin's custom tables, see includes/Repositories/).
	 *
	 * @param string $title Exact post title to match.
	 * @return int|null Post ID, or null if not found.
	 */
	private function find_course_by_title( string $title ): ?int {
		global $wpdb;

		$id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_title = %s AND post_status != 'trash' LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Post_Types::COURSE,
				$title
			)
		);

		return null === $id ? null : (int) $id;
	}

	/**
	 * Find or create a CS/EN pair of terms in one taxonomy, linking the
	 * translation when Polylang is active. Idempotent: re-running `wp rd
	 * seed` reuses the same pair instead of creating duplicates, including
	 * when the Czech and English names are identical (e.g. "Salsa") — the
	 * Czech term is found by name *and* language (`find_term_in_language()`),
	 * and the English one is then found via its translation group
	 * (`pll_get_term()`), never by name alone.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @param string $name_cs  Czech term name.
	 * @param string $name_en  English term name.
	 * @return array{cs: int, en: int} Term IDs (equal to each other when
	 *                                 Polylang is absent — a single canonical term).
	 */
	private function paired_term( string $taxonomy, string $name_cs, string $name_en ): array {
		$cs_id = $this->find_term_in_language( $taxonomy, $name_cs, Lang::CS );

		if ( ! $cs_id ) {
			$cs_id = $this->insert_or_get_term( $name_cs, $taxonomy );

			if ( $cs_id && function_exists( 'pll_set_term_language' ) ) {
				pll_set_term_language( $cs_id, Lang::CS );
			}
		}

		if ( ! function_exists( 'pll_get_term' ) ) {
			return array(
				'cs' => $cs_id,
				'en' => $cs_id,
			);
		}

		$en_id = (int) pll_get_term( $cs_id, Lang::EN );

		if ( ! $en_id ) {
			// WordPress core forbids two terms with the same name at the same
			// taxonomy level unless an explicit, distinct slug is passed (see
			// `wp_insert_term()`); some styles/levels are spelled identically
			// in both languages (e.g. "Salsa"), so force a distinct slug for
			// the English term whenever the names collide, otherwise
			// `wp_insert_term()` would return the *Czech* term's ID as
			// "already exists" and we'd wrongly relabel it as English.
			$en_slug = strtolower( $name_cs ) === strtolower( $name_en )
				? sanitize_title( $name_en ) . '-en'
				: '';

			$en_id = $this->insert_or_get_term( $name_en, $taxonomy, $en_slug );

			if ( $en_id ) {
				pll_set_term_language( $en_id, Lang::EN );
				pll_save_term_translations(
					array(
						Lang::CS => $cs_id,
						Lang::EN => $en_id,
					)
				);
			}
		}

		return array(
			'cs' => $cs_id,
			'en' => 0 !== $en_id ? $en_id : $cs_id,
		);
	}

	/**
	 * Find an existing term by name, restricted to the given language when
	 * Polylang is active. Needed instead of a plain `get_term_by( 'name',
	 * ... )` because two Polylang terms can legitimately share a name across
	 * languages (e.g. "Salsa" in both CS and EN) — without the language
	 * filter, a second `wp rd seed` run could pick either one at random.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @param string $name     Term name to match.
	 * @param string $lang     Language slug required when Polylang is active.
	 * @return int Term ID, or 0 if not found.
	 */
	private function find_term_in_language( string $taxonomy, string $name, string $lang ): int {
		$candidates = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'name'       => $name,
				'hide_empty' => false,
			)
		);

		if ( ! is_array( $candidates ) || array() === $candidates ) {
			return 0;
		}

		if ( ! function_exists( 'pll_get_term_language' ) ) {
			return (int) $candidates[0]->term_id;
		}

		foreach ( $candidates as $candidate ) {
			if ( pll_get_term_language( $candidate->term_id ) === $lang ) {
				return (int) $candidate->term_id;
			}
		}

		return 0;
	}

	/**
	 * Insert a term, tolerating the "term already exists" case (returns the
	 * existing term's ID instead of failing) — needed because
	 * `get_term_by( 'name', ... )` alone cannot distinguish two Polylang
	 * terms that share a name across languages (see `paired_term()`).
	 *
	 * @param string $name     Term name.
	 * @param string $taxonomy Taxonomy slug.
	 * @param string $slug     Optional explicit slug (see `paired_term()`:
	 *                         needed to insert an English term whose name is
	 *                         spelled the same as its Czech counterpart).
	 * @return int Term ID (0 on unexpected failure).
	 */
	private function insert_or_get_term( string $name, string $taxonomy, string $slug = '' ): int {
		$args   = '' === $slug ? array() : array( 'slug' => $slug );
		$result = wp_insert_term( $name, $taxonomy, $args );

		if ( is_wp_error( $result ) ) {
			$existing = $result->get_error_data( 'term_exists' );

			return $existing ? (int) $existing : 0;
		}

		return (int) $result['term_id'];
	}
}
