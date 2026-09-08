# GEREQ Forms & FormGenerator

Dokumentace admin formulářů: atributy na entitách, automatické vykreslení polí, napojení na validation rulesety a UI komponenty (`ui/input`, `ui/select`, …).

| | |
|---|---|
| Namespace | `Gereq\Admin\Form` |
| Služba | `forms()` / `Gereq\Admin\Form\Forms` |
| Twig | `forms()`, `field_required()`, `ui/form_generator.html.twig` |
| Referenční modul | `modules/Listings` (entita `Listing` + `Resources/views/form.html.twig`) |

---

## Obsah

1. [Proč to existuje](#1-proč-to-existuje)
2. [Architektura](#2-architektura)
3. [Rychlý start](#3-rychlý-start)
4. [Atributy](#4-atributy)
5. [API `Forms`](#5-api-forms)
6. [Typy polí a UI mapování](#6-typy-polí-a-ui-mapování)
7. [Selecty, choices a searchable](#7-selecty-choices-a-searchable)
8. [Hodnoty (create vs edit)](#8-hodnoty-create-vs-edit)
9. [Required, ruleset, FormEntity](#9-required-ruleset-formentity)
10. [Hybridní formuláře (doporučený vzor)](#10-hybridní-formuláře-doporučený-vzor)
11. [Kompletní příklad: Listing](#11-kompletní-příklad-listing)
12. [Další příklady](#12-další-příklady)
13. [Soubory v repu](#13-soubory-v-repu)
14. [Pravidla a anti-patterny](#14-pravidla-a-anti-patterny)
15. [FAQ](#15-faq)

---

## 1. Proč to existuje

Cíl není Symfony Form ani Filament. Cíl je **konvence pro admin CRUD**:

- méně opakovaného Twigu (`{% include 'ui/input' %}` × 30),
- jedna pravda pro `required` (atribut + ruleset → HTML `*` / `required`),
- stejné UI komponenty jako ruční formuláře,
- speciální UI (ARES, galerie, vztahy) zůstává v Twigu.

**Hodí se na:** jednoduché až střední entity, sekce formuláře (group), enum selecty z konstant.

**Nehodí se na:** 100 % layout hub formuláře, drag&drop, složité widgety — ty nech ručně.

---

## 2. Architektura

```text
┌─────────────────────────────────────────────────────────────┐
│  Domain entita                                              │
│  #[FormGenerator] + #[FormEntity] + #[FormField] na props   │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│  Forms::fields() / render()                                 │
│  • filtr group / only / exclude                             │
│  • choicesFrom → Class::CONST                               │
│  • hodnoty: override → instance → property default          │
│  • order                                                    │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│  ui/form_generator.html.twig                                │
│  type → ui/input | ui/select | ui/checkbox | ui/textarea …  │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│  Browser: Alpine (gereqSelect, gereqForm, TipTap, …)        │
│  POST: klasická jména polí (status, broker_id, …)           │
└─────────────────────────────────────────────────────────────┘
```

Twig šablona stránky zůstává zodpovědná za:

- layout (panely, taby, header, CTA),
- CSRF + `data-form`,
- runtime `choices` (např. seznam brokerů z DB),
- speciální bloky (ARES, nearby, images).

---

## 3. Rychlý start

### 3.1 Entita

```php
<?php

declare(strict_types=1);

namespace Modules\Demo\Domain;

use Doctrine\ORM\Mapping as ORM;
use Gereq\Admin\Form\FormEntity;
use Gereq\Admin\Form\FormField;
use Gereq\Admin\Form\FormGenerator;

#[ORM\Entity]
#[ORM\Table(name: 'demo_items')]
#[FormEntity('demo.save')]
#[FormGenerator(exclude: ['id', 'createdAt'], form: 'demo.save', cols: 2)]
class Item
{
    public const STATUSES = ['draft', 'active'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 190)]
    #[FormField(required: true, label: 'demo.field.title')]
    private string $title;

    #[ORM\Column(length: 32)]
    #[FormField(
        label: 'demo.field.status',
        type: 'select',
        choicesFrom: 'STATUSES',
        choiceLabel: 'demo.status.',
    )]
    private string $status = 'draft';

    // …
}
```

### 3.2 Registrace v modulu

```php
$validation->ruleset('demo.save', [
    'title' => 'required|min:1|max:190',
    'status' => 'required|in:draft,active',
]);
$context->forms()->entity('demo.save', \Modules\Demo\Domain\Item::class);
```

`#[FormEntity('demo.save')]` binduje entitu automaticky při indexaci; explicitní `forms()->entity()` je doporučené v `registerValidation()` kvůli jasnosti a pořadí bootu.

### 3.3 Twig

```twig
<form method="post" action="{{ url('admin.demo.store') }}" data-form="demo.save" x-data="gereqForm">
    {{ csrf_field()|raw }}

    {{ forms().render(item ?? 'Modules\\Demo\\Domain\\Item', {
        form: 'demo.save',
        cols: 2
    }) }}

    <button type="submit" class="btn btn--primary">{{ 'common.save'|trans }}</button>
</form>
```

- **Create:** `forms().render('Modules\\Demo\\Domain\\Item', …)` — hodnoty z property defaultů.
- **Edit:** `forms().render(item, …)` — hodnoty z instance.

---

## 4. Atributy

### 4.1 `#[FormGenerator]` (class)

| Parametr | Default | Význam |
|----------|---------|--------|
| `exclude` | `['id']` | Property names přeskočené při renderu |
| `form` | `null` | Jméno formuláře / rulesetu pro `old()` / `error()` / required |
| `cols` | `2` | Default sloupců gridu (`1`–`4`) |

```php
#[FormGenerator(
    exclude: ['id', 'createdAt', 'updatedAt'],
    form: 'listings.save',
    cols: 3,
)]
```

### 4.2 `#[FormEntity]` (class, repeatable)

Váže entitu k jednomu nebo více jménům formuláře (ruleset), aby `forms()->requiredFields('listings.save')` bralo i `#[FormField(required: true)]`.

```php
#[FormEntity('listings.save')]
#[FormEntity('listings.import')] // další form, pokud potřeba
```

### 4.3 `#[FormField]` (property)

| Parametr | Default | Význam |
|----------|---------|--------|
| `required` | `false` | Povinné pole (`*` + HTML `required`, merge do required map) |
| `name` | `null` | Override request jména (jinak Doctrine column name / snake_case) |
| `label` | `''` | Trans key nebo text (v generatoru se volá `\|trans`) |
| `type` | `'text'` | Viz [§6](#6-typy-polí-a-ui-mapování) |
| `options` | `[]` | UI extras: `placeholder`, `maxlength`, `step`, `min`, `max`, `searchable`, `class` → `extra` |
| `choices` | `[]` | Statické options selectu (preferuj `choicesFrom`) |
| `choicesFrom` | `''` | Název class konstanty (`'STATUSES'` → `Listing::STATUSES`) |
| `choiceLabel` | `''` | Prefix trans klíče: `prefix + value` (např. `listings.status.` → `listings.status.draft`) |
| `emptyLabel` | `''` | Prázdná volba `value=""` (literál nebo trans key) |
| `hint` | `''` | Nápověda pod polem (trans key) |
| `extra` | `''` | CSS třídy wrapperu (`field--full`, …) |
| `group` | `''` | Sekce / tab (`basic`, `location`, …) |
| `attrs` | `''` | Raw HTML atributy na control (`data-ares-fill="city"`) |
| `order` | `0` | Řazení v groupě (nižší dřív; při shodě declaration order) |

**Jméno pole v requestu**

1. `FormField::$name`, jinak  
2. `#[ORM\Column(name: '…')]`, jinak  
3. snake_case property (`marketStatus` → `market_status`).

---

## 5. API `Forms`

### PHP

```php
forms()->render($source, $options);           // Twig\Markup HTML
forms()->fields($source, $options);           // list<GeneratedField>
forms()->requiredFields($source);             // list<string>
forms()->requiredMap($source);                // array<string, true>
forms()->isRequired($source, 'title');        // bool
forms()->entity('demo.save', Item::class);    // bind form → entity
forms()->resolveRequired($name, $form, $explicit, $map, $entity);
```

`$source` = class-string **nebo** instance entity.

### `render()` / `fields()` options

```php
[
    'form'    => 'listings.save',           // old/error/required context
    'group'   => 'basic',                   // jen pole s tímto group
    'cols'    => 3,                         // 1|2|3|4
    'wrap'    => true,                      // obalit form-grid (false = vnořit do existujícího gridu)
    'exclude' => ['slug'],                  // navíc k FormGenerator::exclude
    'only'    => ['title', 'status'],       // whitelist (property nebo field name)
    'values'  => ['currency' => 'EUR'],     // override hodnot
    'choices' => [                          // override options selectů
        'broker_id' => [
            ['value' => '', 'label' => '—'],
            ['value' => 'uuid', 'label' => 'Jan Novák'],
        ],
    ],
]
```

### Twig

```twig
{{ forms().render(entity, { group: 'basic', cols: 3 }) }}

{# Jen required mapa do šablony (ruční include) #}
{% set requiredFields = forms().requiredMap(listing) %}
{% include 'ui/input.html.twig' with {
    name: 'title',
    form: 'listings.save',
    requiredFields: requiredFields,
    entity: listing
} %}
```

`field_required(name, form, required, requiredFields, entity)` — helper používaný uvnitř `ui/*`.

---

## 6. Typy polí a UI mapování

| `type` | Twig include | Poznámka |
|--------|--------------|----------|
| `text`, `email`, `number`, `password`, `url`, `color` | `ui/input.html.twig` | `options.step|min|max|maxlength|placeholder` |
| `textarea` | `ui/textarea` (mini) | |
| `editor` | `ui/textarea` → TipTap (`gereqEditor`) | `extra: 'field--full'` typicky |
| `select` | `ui/select.html.twig` | custom listbox + hidden input; `options.searchable` |
| `checkbox` | `ui/checkbox` variant `check` | |
| `switch` | `ui/checkbox` → `ui/switch` | hidden `0`/`1` |
| `hidden` | holý `<input type="hidden">` | |

Mapování je v `resources/views/ui/form_generator.html.twig`.

---

## 7. Selecty, choices a searchable

### 7.1 Enum z konstanty (doporučeno)

```php
public const OFFERS = ['sale', 'rent'];

#[FormField(
    type: 'select',
    label: 'listings.field.offer',
    choicesFrom: 'OFFERS',
    choiceLabel: 'listings.offer.',
)]
private string $offerType = 'sale';
```

Výsledek choices (před `\|trans` v selectu):

```php
[
    ['value' => 'sale', 'label' => 'listings.offer.sale'],
    ['value' => 'rent', 'label' => 'listings.offer.rent'],
]
```

Labely se překládají v `ui/select.html.twig` přes `optLabel|trans` (ne v PHP).

### 7.2 Prázdná volba

```php
#[FormField(
    type: 'select',
    choicesFrom: 'ENERGY',
    emptyLabel: '—',           // literál
    // emptyLabel: 'common.none', // nebo trans key
)]
private ?string $energyRating = null;
```

### 7.3 Statické `choices` na atributu

```php
#[FormField(
    type: 'select',
    choices: [
        ['value' => 'a', 'label' => 'demo.choice.a'],
        'b', // → value i label "b"
    ],
)]
```

Preferuj `choicesFrom`, ať nemáš duplicitu s konstantami entity.

### 7.4 Runtime choices (vztahy z DB)

Entita **nezná** seznam brokerů. Twig / controller dodá override:

```twig
{% set brokerSelectOptions = [
    { value: '', label: 'listings.field.broker.none'|trans }
]|merge(brokerOptions) %}

{{ forms().render(listingSource, {
    group: 'broker',
    choices: { broker_id: brokerSelectOptions }
}) }}
```

Override **nahradí** `choicesFrom` / `choices` / `emptyLabel` pro dané pole.

### 7.5 Searchable select

```php
#[FormField(
    type: 'select',
    options: ['searchable' => true],
    // …
)]
```

→ `ui/select` dostane `searchable: true` → `data-searchable="1"` + search input.  
Alpine `gereqSelect` filtruje options podle `data-search`.

Do POST jde vždy **hidden** `<input name="…" value="…">`, ne nativní `<select>`.

### 7.6 Tok selectu

```text
#[FormField type=select]
        ↓
Forms::choicesFor()  →  [{value, labelKey}]
        ↓
ui/select  →  label|trans, hidden input, gereqSelect
        ↓
POST status=published
```

---

## 8. Hodnoty (create vs edit)

Priorita hodnoty pole:

1. `options['values'][$name]` (override z Twigu/PHP)  
2. hodnota z instance (`$entity->status()` / getter / reflection)  
3. **property default** (`private string $status = 'draft'`)  
4. `null`

Proto u create **nemusíš** skládat `createValues` pro status/price/featured — stačí defaulty na properties. Override jen pro věci mimo entitu:

```twig
values: listing ? {} : { currency: currency_code() }
```

---

## 9. Required, ruleset, FormEntity

Required pole vznikají sloučením:

1. `#[FormField(required: true)]` / `#[AdminField(required: true)]`
2. validation ruleset (`'title' => 'required|…'`) přes `forms()->entity($form, $class)` / `#[FormEntity]`

```php
// Modul
$validation->ruleset('listings.save', [
    'title' => 'required|min:1|max:190',
    'slug' => 'nullable|max:190',
]);
$context->forms()->entity('listings.save', Listing::class);
```

UI komponenty volají `field_required(…)` → HTML `required` + vizuální `*`.

Server-side `Controller::validate($request, 'listings.save')` používej tam, kde má smysl; některá CRUD (např. listing payload) mohou validovat ručně — required mapa v UI i tak pomáhá.

---

## 10. Hybridní formuláře (doporučený vzor)

```twig
{# Tab / panel chrome ručně #}
<section class="design-panel" data-panel="basic">
    <section class="panel">
        <header class="panel__head"><h2>…</h2></header>
        {{ forms().render(source, { group: 'basic', cols: 3, … }) }}
    </section>
</section>

{# Speciální UI + generator do stejného gridu #}
<div class="form-grid form-grid--cols-3" x-data="gereqAresAddress">
    {# ruční ARES address #}
    …
    {{ forms().render(source, { group: 'location', wrap: false }) }}
</div>

{# Nearby / images — úplně mimo listing-form generator #}
```

**Pravidlo:** generator = standardní pole; Twig = layout + vztahy + widgety.

**Nedělat:** `class ListingCreateForm extends Listing` kvůli atributům — UI metadata z parent properties neodstraníš a Doctrine inheritance je past.

---

## 11. Kompletní příklad: Listing

### Entita (zkráceno)

```php
#[FormEntity('listings.save')]
#[FormGenerator(
    exclude: ['id', 'images', 'coverImageUrl', 'publishedAt', 'createdAt', 'updatedAt',
              'brokerName', 'brokerEmail', 'brokerPhone', 'brokerPosition', 'brokerPhotoUrl'],
    form: 'listings.save',
    cols: 3,
)]
class Listing
{
    public const STATUSES = ['draft', 'published', 'archived'];

    #[FormField(required: true, label: 'listings.field.title', group: 'basic', extra: 'field--full', order: 10)]
    private string $title;

    #[FormField(
        label: 'listings.field.status',
        type: 'select',
        group: 'basic',
        choicesFrom: 'STATUSES',
        choiceLabel: 'listings.status.',
        order: 30,
    )]
    private string $status = 'draft';

    #[FormField(label: 'listings.field.featured', type: 'switch', group: 'basic', order: 70)]
    private bool $featured = false;

    #[FormField(label: 'listings.field.description', type: 'editor', group: 'basic', extra: 'field--full', order: 160)]
    private ?string $description = null;

    #[FormField(label: 'listings.field.city', group: 'location', attrs: 'data-ares-fill="city"', order: 20)]
    private string $city = '';

    #[FormField(
        label: 'listings.field.broker',
        type: 'select',
        group: 'broker',
        options: ['searchable' => true],
        emptyLabel: 'listings.field.broker.none',
    )]
    private ?string $brokerId = null;
}
```

### Twig (zkráceno)

```twig
{% set listingSource = listing ?? 'Modules\\Listings\\Domain\\Listing' %}
{% set brokerSelectOptions = [{ value: '', label: 'listings.field.broker.none'|trans }]
    |merge(brokerOptions|default([])) %}

<form id="listing-form" method="post"
      action="{{ listing ? url('admin.listings.update', {id: listing.id()}) : url('admin.listings.store') }}"
      data-form="listings.save" x-data="gereqForm">
    {{ csrf_field()|raw }}

    {{ forms().render(listingSource, {
        form: 'listings.save',
        group: 'basic',
        cols: 3,
        values: listing ? {} : { currency: currency_code() }
    }) }}

    <div class="form-grid form-grid--cols-3" x-data="gereqAresAddress">
        {# ruční address + ARES #}
        {{ forms().render(listingSource, {
            form: 'listings.save',
            group: 'location',
            wrap: false
        }) }}
    </div>

    {{ forms().render(listingSource, { form: 'listings.save', group: 'amenities', cols: 3 }) }}

    {{ forms().render(listingSource, {
        form: 'listings.save',
        group: 'broker',
        cols: 1,
        choices: { broker_id: brokerSelectOptions }
    }) }}
</form>
```

Soubory: `modules/Listings/Domain/Listing.php`, `modules/Listings/Resources/views/form.html.twig`.

---

## 12. Další příklady

### 12.1 Jen text + switch (City-like)

```php
#[FormGenerator(form: 'listings.cities.save', cols: 1)]
class City
{
    #[FormField(required: true, label: 'listings.cities.field.name')]
    private string $name;

    #[FormField(label: 'listings.cities.field.active', type: 'switch')]
    private bool $active = true;
}
```

```twig
{{ forms().render(item ?? 'Modules\\Listings\\Domain\\City', {
    form: 'listings.cities.save',
    cols: 1
}) }}
```

### 12.2 Jen vybraná pole (`only`)

```twig
{{ forms().render(listing, {
    form: 'listings.save',
    only: ['title', 'status', 'price'],
    cols: 3
}) }}
```

### 12.3 PHP bez Twig stránky (např. test / partial)

```php
$html = forms()->render($listing, [
    'form' => 'listings.save',
    'group' => 'basic',
    'cols' => 3,
]);

$fields = forms()->fields(Listing::class, ['group' => 'broker']);
foreach ($fields as $field) {
    // $field->name, ->type, ->choices, ->value, ->order, …
}
```

### 12.4 Ruční pole + required z entity

```twig
{% include 'ui/input.html.twig' with {
    name: 'title',
    label: 'listings.field.title'|trans,
    form: 'listings.save',
    value: listing ? listing.title() : '',
    entity: listing ?? 'Modules\\Listings\\Domain\\Listing'
} %}
```

`entity` / `form` stačí, aby `field_required` našel `#[FormField(required: true)]`.

---

## 13. Soubory v repu

| Soubor | Role |
|--------|------|
| `src/Admin/Form/FormField.php` | Atribut pole |
| `src/Admin/Form/FormGenerator.php` | Atribut entity (render) |
| `src/Admin/Form/FormEntity.php` | Bind form name ↔ entita |
| `src/Admin/Form/Forms.php` | Služba fields/render/required |
| `src/Admin/Form/GeneratedField.php` | DTO jednoho pole |
| `resources/views/ui/form_generator.html.twig` | type → UI include |
| `resources/views/ui/{input,select,checkbox,switch,textarea,editor}.html.twig` | Kontrolky |
| `resources/js/ui/select.ts` | `gereqSelect` |
| `resources/js/ui/form.ts` | `gereqForm` (draft, invalid, fingerprint) |
| `tests/Unit/FormsTest.php` | Unit testy |
| `src/Support/helpers.php` | `forms()` |
| `src/View/TwigFactory.php` | Twig funkce `forms`, `field_required` |

---

## 14. Pravidla a anti-patterny

**Dělej**

- Drž `group` + `order` konzistentní v rámci entity.
- Enumy jako `public const` + `choicesFrom` + `choiceLabel`.
- Runtime seznamy jen přes `choices` override.
- Hybrid: generator pro CRUD pole, ruční Twig pro widgety.
- Po úspěšném save volej `forgetForm('….save')` (draft sessionStorage).

**Nedělej**

- Nestav celý Filament na atributech — Doména pak umře pod UI metadaty.
- Neděď Doctrine entitu jen kvůli „Form“ třídě.
- Nepřekládej choice labely v PHP a znovu v Twigu různými cestami — labely jako keys, `\|trans` v `ui/select`.
- Nedávej `display:none` na TipTap mount (editor).
- Nečekej, že generator vyřeší staging/publish — to je controller (`ProductionFreeze`).

---

## 15. FAQ

**Proč select ukazuje `listings.status.draft`?**  
Label je trans key; musí projít `\|trans` v `ui/select`. Pokud vidíš raw key, chybí překlad v `messages.*.php` nebo starý select bez `\|trans`.

**Proč searchable nefiltruje?**  
Potřebuješ `options: ['searchable' => true]` na `FormField` a Alpine `gereqSelect` v buildu. Options musí mít smysluplné `data-search` (default = label).

**Create má prázdný status?**  
Nastav property default (`= 'draft'`). Override `values` jen když default nestačí.

**Jak vyloučit pole z generatoru, ale nechat ho v DB?**  
Vynech `#[FormField]` (např. `address` u listingu) nebo přidej do `FormGenerator::exclude` / `exclude` v render options.

**Lze mít dvě různé groupy ve dvou tabech?**  
Ano — dvě volání `forms().render(..., { group: 'basic' })` a `{ group: 'location' }` v jednom `<form>`.

**Je to náhrada za Admin Resource?**  
Ne. Admin Resource řeší CRUD scaffold / menu; Forms řeší vykreslení a required metadata. Mohou se doplňovat.

---


---

## Změny API (orientačně)

Při úpravách atributů nebo `Forms::render` options aktualizuj tento dokument a `tests/Unit/FormsTest.php`.
