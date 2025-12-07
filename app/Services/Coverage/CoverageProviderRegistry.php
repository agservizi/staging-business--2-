<?php
declare(strict_types=1);

namespace App\Services\Coverage;

use InvalidArgumentException;

final class CoverageProviderRegistry
{
    /**
     * @var array<string,array{
     *     key:string,
     *     label:string,
     *     url:string,
     *     category:string,
     *     automation_status:string,
     *     fields:array<string,array{label:string,required:bool}>,
     *     actions:array<int,array<string,mixed>>,
     *     notes?:string
     * }>
     */
    private const PROVIDERS = [
        'fastweb_consumer' => [
            'key' => 'fastweb_consumer',
            'label' => 'Fastweb – Consumer',
            'url' => 'https://www.fastweb.it/AVT/',
            'category' => 'consumer',
            'automation_status' => 'beta',
            'notes' => 'Ricetta euristica: riempie i campi cercando keyword comuni (indirizzo, civico, CAP). Dopo l\'invio attende la sezione risultati e ne estrae il testo.',
            'fields' => [
                'address' => ['label' => 'Indirizzo', 'required' => true],
                'civic' => ['label' => 'Civico', 'required' => false],
                'cap' => ['label' => 'CAP', 'required' => false],
                'city' => ['label' => 'Comune', 'required' => false],
            ],
            'actions' => [
                ['action' => 'wait_for', 'selector' => '#AVTframe', 'timeout' => 30],
                ['action' => 'switch_to_frame', 'selector' => '#AVTframe', 'timeout' => 30],
                ['action' => 'wait_for', 'selector' => 'form.avt_form textarea.avt_address'],
                [
                    'action' => 'type',
                    'selector' => 'textarea:is(.avt_address,[name*="address" i],[placeholder*="indirizzo" i])',
                    'concat_fields' => ['address', 'civic', 'cap', 'city'],
                ],
                ['action' => 'pause', 'milliseconds' => 600],
                [
                    'action' => 'wait_for',
                    'selector' => 'ul.ui-menu li.ui-menu-item div.ui-menu-item-wrapper',
                    'timeout' => 8,
                    'continue_on_error' => true,
                ],
                [
                    'action' => 'click',
                    'selector' => 'ul.ui-menu li.ui-menu-item:first-child div.ui-menu-item-wrapper',
                    'continue_on_error' => true,
                ],
                ['action' => 'pause', 'milliseconds' => 400],
                ['action' => 'click', 'selector' => 'a.linkCoverage', 'scroll' => true],
                ['action' => 'wait_for', 'selector' => 'div.res_nocop:not(.hidden), div[class*="res_cov" i]:not(.hidden)', 'timeout' => 45, 'continue_on_error' => true],
                ['action' => 'pause', 'milliseconds' => 2000],
                [
                    'action' => 'extract_text',
                    'selector' => 'div.res_nocop:not(.hidden), div[class*="res_cov" i]:not(.hidden)',
                    'as' => 'copertura',
                    'continue_on_error' => true,
                ],
                [
                    'action' => 'extract_text',
                    'selector' => 'body',
                    'as' => 'pagina_fastweb',
                    'continue_on_error' => true,
                ],
                ['action' => 'switch_to_default_content'],
            ],
        ],
        'windtre_consumer' => [
            'key' => 'windtre_consumer',
            'label' => 'WINDTRE – Consumer',
            'url' => 'https://www.windtre.it/copertura-fibra-mobile-5g',
            'category' => 'consumer',
            'automation_status' => 'beta',
            'notes' => 'Compila indirizzo/comune e avvia il check fibra. Il risultato viene letto dalla prima card che contiene parole chiave come "copertura".',
            'fields' => [
                'address' => ['label' => 'Indirizzo', 'required' => true],
                'city' => ['label' => 'Comune', 'required' => true],
                'cap' => ['label' => 'CAP', 'required' => false],
            ],
            'actions' => [
                ['action' => 'wait_for', 'selector' => 'a[href*="check-coverage-super-fibra" i], .js-external-popup-open, [data-button-text-desk*="copertura fibra" i], #copertura .coverage-fields, .coverage-fields input#pac-input', 'timeout' => 35, 'continue_on_error' => true],
                ['action' => 'wait_for', 'selector' => '.cmp-cookie-consent__accept button, button[id*="accept" i], button[class*="accept" i], button[name*="accept" i], button[data-cookie*="accept" i], button[data-action*="accept" i], button[data-gtm*="consent" i], button[data-testid*="accept" i], #cookiebar-accept, #cookiebar button.accept, #onetrust-accept-btn-handler', 'timeout' => 5, 'continue_on_error' => true],
                ['action' => 'click', 'selector' => '.cmp-cookie-consent__accept button, button[id*="accept" i], button[class*="accept" i], button[name*="accept" i], button[data-cookie*="accept" i], button[data-action*="accept" i], button[data-gtm*="consent" i], button[data-testid*="accept" i], #cookiebar-accept, #cookiebar button.accept, #onetrust-accept-btn-handler', 'continue_on_error' => true],
                ['action' => 'click', 'selector' => 'button[id*="reject" i], button[class*="reject" i], button[name*="reject" i], button[data-action*="reject" i], button[data-action*="continue" i], button[data-testid*="reject" i], #cookiebar-continue, #cookiebar button.continue', 'continue_on_error' => true],
                ['action' => 'execute_script', 'script' => '(function(){var overlays=document.querySelectorAll("#cookiebar,.cmp-cookie-consent,#onetrust-consent-sdk");overlays.forEach(function(el){el.style.display="none";});})();', 'continue_on_error' => true],
                ['action' => 'pause', 'milliseconds' => 500],
                [
                    'action' => 'execute_script',
                    'script' => '(function(){var target=document.querySelector("#copertura, .coverage");if(!target){return;}try{target.scrollIntoView({behavior:"smooth",block:"start"});}catch(e){window.scrollTo(0,target.getBoundingClientRect().top+window.scrollY-100);}})();',
                    'continue_on_error' => true,
                ],
                ['action' => 'pause', 'milliseconds' => 600],
                ['action' => 'click', 'selector' => 'a[href*="check-coverage-super-fibra" i], .js-external-popup-open, [data-button-text-desk*="copertura fibra" i]', 'scroll' => true, 'continue_on_error' => true],
                [
                    'action' => 'execute_script',
                    'script' => '(function(){var inlineSelector="#pac-input, .coverage-fields input[id*=\'pac\' i], .coverage-fields input[placeholder*=\'indirizzo\' i]";var inlineInput=document.querySelector(inlineSelector);if(inlineInput){try{inlineInput.scrollIntoView({behavior:\'smooth\',block:\'center\'});}catch(e){}try{inlineInput.focus();}catch(e){}window.__windtreInlineForm=true;return;}window.__windtreInlineForm=false;var selectors="a[href*=\'check-coverage-super-fibra\' i], .js-external-popup-open, [data-button-text-desk*=\'copertura fibra\' i]";var fallback="https://www.windtre.it/content/experience-fragments/windtre/it/it/site/modale-verifica-copertura/check-coverage-super-fibra.html";var openModal=function(){var btn=document.querySelector(selectors);if(!btn){return false;}try{btn.scrollIntoView({behavior:\'smooth\',block:\'center\'});}catch(e){}btn.click();return true;};if(window.__windtreModalInterval){clearInterval(window.__windtreModalInterval);}if(openModal()){return;}var attempts=0;window.__windtreModalInterval=setInterval(function(){attempts++;if(openModal()){clearInterval(window.__windtreModalInterval);}else if(attempts>=60){clearInterval(window.__windtreModalInterval);if(!window.__windtreInlineForm){window.location.href=fallback;}}},500);})();',
                    'continue_on_error' => true,
                ],
                ['action' => 'wait_for', 'selector' => '#js-external-popup-placeholder iframe, #js-external-popup-placeholder form, form[action*="check-coverage-super-fibra" i], form input:is([name*="address" i],[placeholder*="indirizzo" i]), .coverage-fields input:is(#pac-input,[placeholder*="indirizzo" i])', 'timeout' => 40],
                ['action' => 'pause', 'milliseconds' => 800],
                [
                    'action' => 'type',
                    'selector' => '.coverage-fields input:is(#pac-input,[placeholder*="indirizzo" i])',
                    'concat_fields' => ['address', 'city', 'cap'],
                    'continue_on_error' => true,
                ],
                ['action' => 'switch_to_frame', 'selector' => '#js-external-popup-placeholder iframe', 'continue_on_error' => true],
                [
                    'action' => 'wait_for',
                    'selector' => 'form input:is([name*="address" i],[placeholder*="indirizzo" i]), .coverage-fields input:is(#pac-input,[placeholder*="indirizzo" i])',
                    'timeout' => 30,
                    'continue_on_error' => true,
                ],
                [
                    'action' => 'type',
                    'selector' => 'form input:is([name*="address" i],[id*="address" i],[placeholder*="indirizzo" i])',
                    'field' => 'address',
                    'continue_on_error' => true,
                ],
                [
                    'action' => 'type',
                    'selector' => 'form input:is([name*="city" i],[name*="comune" i],[placeholder*="comune" i])',
                    'field' => 'city',
                    'continue_on_error' => true,
                ],
                [
                    'action' => 'type',
                    'selector' => 'form input:is([name*="cap" i],[id*="cap" i])',
                    'field' => 'cap',
                    'continue_on_error' => true,
                ],
                [
                    'action' => 'click',
                    'selector' => 'button:is([type="submit"],[data-gtm*="fibra" i],[data-cta*="verifica" i])',
                    'scroll' => true,
                    'continue_on_error' => true,
                ],
                [
                    'action' => 'click',
                    'selector' => '.coverage-fields__searchbar__search .control__search',
                    'scroll' => true,
                    'continue_on_error' => true,
                ],
                [
                    'action' => 'execute_script',
                    'script' => '(function(){var input=document.querySelector(".coverage-fields input#pac-input, .coverage-fields input[placeholder*=\"indirizzo\" i]");if(!input){return;}var down=new KeyboardEvent("keydown",{key:"Enter",keyCode:13,which:13,bubbles:true});var up=new KeyboardEvent("keyup",{key:"Enter",keyCode:13,which:13,bubbles:true});input.dispatchEvent(down);input.dispatchEvent(up);})();',
                    'continue_on_error' => true,
                ],
                ['action' => 'wait_for', 'selector' => ':is(div,section)[class*="result" i], :is(div,section)[class*="copertura" i], body', 'timeout' => 35],
                ['action' => 'extract_text', 'selector' => ':is(div,section)[class*="result" i], :is(div,section)[class*="copertura" i], body', 'as' => 'copertura'],
                ['action' => 'switch_to_default_content', 'continue_on_error' => true],
            ],
        ],
        'enel_consumer' => [
            'key' => 'enel_consumer',
            'label' => 'Enel Energia – Consumer',
            'url' => 'https://www.enel.it/it-it/verifica-copertura-fibra',
            'category' => 'consumer',
            'automation_status' => 'beta',
            'notes' => 'Selenium segue il wizard: indirizzo, civico, comune e step con pulsanti “Avanti”. Estrarre la card riassuntiva.',
            'fields' => [
                'address' => ['label' => 'Indirizzo', 'required' => true],
                'civic' => ['label' => 'Civico', 'required' => true],
                'city' => ['label' => 'Comune', 'required' => true],
            ],
            'actions' => [
                ['action' => 'wait_for', 'selector' => 'form, [data-step="address"]'],
                ['action' => 'type', 'selector' => 'input:is([name*="indir" i],[placeholder*="indirizzo" i])', 'field' => 'address'],
                ['action' => 'type', 'selector' => 'input:is([name*="civ" i],[placeholder*="civico" i])', 'field' => 'civic'],
                ['action' => 'type', 'selector' => 'input:is([name*="comune" i],[placeholder*="comune" i])', 'field' => 'city'],
                ['action' => 'click', 'selector' => 'button:is([type="submit"],[data-action*="next" i],[data-testid*="avanti" i])', 'scroll' => true],
                ['action' => 'wait_for', 'selector' => ':is(div,section)[class*="result" i], :is(div,section)[class*="esito" i], body', 'timeout' => 40],
                ['action' => 'extract_text', 'selector' => ':is(div,section)[class*="result" i], :is(div,section)[class*="esito" i], body', 'as' => 'copertura'],
            ],
        ],
        'fastweb_business' => [
            'key' => 'fastweb_business',
            'label' => 'Fastweb – Business',
            'url' => 'https://www.fastweb.it/adsl-aziende/fastweb-business/',
            'category' => 'business',
            'automation_status' => 'beta',
            'notes' => 'Compila indirizzo/azienda nel form commerciale e cattura il messaggio di conferma.',
            'fields' => [
                'company' => ['label' => 'Ragione sociale', 'required' => false],
                'address' => ['label' => 'Indirizzo', 'required' => true],
                'civic' => ['label' => 'Civico', 'required' => true],
                'cap' => ['label' => 'CAP', 'required' => true],
                'city' => ['label' => 'Comune', 'required' => true],
            ],
            'actions' => [
                ['action' => 'wait_for', 'selector' => 'form'],
                ['action' => 'type', 'selector' => 'input:is([name*="company" i],[name*="ragione" i])', 'field' => 'company'],
                ['action' => 'type', 'selector' => 'input:is([name*="indir" i],[name*="address" i])', 'field' => 'address'],
                ['action' => 'type', 'selector' => 'input:is([name*="civ" i])', 'field' => 'civic'],
                ['action' => 'type', 'selector' => 'input:is([name*="cap" i],[name*="zip" i])', 'field' => 'cap'],
                ['action' => 'type', 'selector' => 'input:is([name*="comune" i],[name*="city" i])', 'field' => 'city'],
                ['action' => 'click', 'selector' => 'button:is([type="submit"],[data-action*="send" i],[data-testid*="submit" i])', 'scroll' => true],
                ['action' => 'wait_for', 'selector' => 'div[class*="modal" i], div[class*="thank" i], body', 'timeout' => 35],
                ['action' => 'extract_text', 'selector' => 'div[class*="modal" i], div[class*="thank" i], body', 'as' => 'esito'],
            ],
        ],
        'windtre_business_coverage' => [
            'key' => 'windtre_business_coverage',
            'label' => 'WINDTRE Business – Copertura nazionale',
            'url' => 'https://www.windtrebusiness.it/partita-iva-aziende/vantaggi-e-rete/copertura-nazionale',
            'category' => 'business',
            'automation_status' => 'beta',
            'notes' => 'Compila il widget di richiesta e legge il box informativo con l\'esito della copertura.',
            'fields' => [
                'address' => ['label' => 'Indirizzo', 'required' => true],
                'city' => ['label' => 'Comune', 'required' => true],
                'cap' => ['label' => 'CAP', 'required' => false],
            ],
            'actions' => [
                ['action' => 'wait_for', 'selector' => 'form'],
                ['action' => 'type', 'selector' => 'input:is([name*="indir" i],[placeholder*="indirizzo" i])', 'field' => 'address'],
                ['action' => 'type', 'selector' => 'input:is([name*="comune" i],[placeholder*="comune" i])', 'field' => 'city'],
                ['action' => 'type', 'selector' => 'input:is([name*="cap" i],[placeholder*="cap" i])', 'field' => 'cap'],
                ['action' => 'click', 'selector' => 'button:is([type="submit"],[data-cta*="copertura" i])', 'scroll' => true],
                ['action' => 'wait_for', 'selector' => 'div[class*="result" i], div[class*="copertura" i], body', 'timeout' => 35],
                ['action' => 'extract_text', 'selector' => 'div[class*="result" i], div[class*="copertura" i], body', 'as' => 'copertura'],
            ],
        ],
        'windtre_business_fiber' => [
            'key' => 'windtre_business_fiber',
            'label' => 'WINDTRE Business – Offerte fibra',
            'url' => 'https://www.windtrebusiness.it/partita-iva-aziende/fisso-e-internet/offerte-fibra',
            'category' => 'business',
            'automation_status' => 'beta',
            'notes' => 'Il wizard viene percorso fino allo step risultati e viene letta la card con tecnologia consigliata.',
            'fields' => [
                'address' => ['label' => 'Indirizzo', 'required' => true],
                'city' => ['label' => 'Comune', 'required' => true],
                'cap' => ['label' => 'CAP', 'required' => true],
                'notes' => ['label' => 'Note interne', 'required' => false],
            ],
            'actions' => [
                ['action' => 'wait_for', 'selector' => 'form, [data-step="address"]'],
                ['action' => 'type', 'selector' => 'input:is([name*="indir" i],[placeholder*="indirizzo" i])', 'field' => 'address'],
                ['action' => 'type', 'selector' => 'input:is([name*="cap" i],[placeholder*="cap" i])', 'field' => 'cap'],
                ['action' => 'type', 'selector' => 'input:is([name*="comune" i],[placeholder*="comune" i])', 'field' => 'city'],
                ['action' => 'click', 'selector' => 'button:is([type="submit"],[data-action*="next" i],[data-cta*="continua" i])', 'scroll' => true],
                ['action' => 'pause', 'milliseconds' => 1500],
                ['action' => 'wait_for', 'selector' => 'section[class*="risult" i], section[class*="result" i], body', 'timeout' => 35],
                ['action' => 'extract_text', 'selector' => 'section[class*="risult" i], section[class*="result" i], body', 'as' => 'copertura'],
            ],
        ],
        'enel_business' => [
            'key' => 'enel_business',
            'label' => 'Enel – Imprese',
            'url' => 'https://www.enel.it/it-it/imprese',
            'category' => 'business',
            'automation_status' => 'beta',
            'notes' => 'Automazione focalizzata sul form di richiesta imprese: precompila dati e registra la conferma visibile.',
            'fields' => [
                'company' => ['label' => 'Ragione sociale', 'required' => false],
                'address' => ['label' => 'Indirizzo', 'required' => true],
                'city' => ['label' => 'Comune', 'required' => true],
                'cap' => ['label' => 'CAP', 'required' => true],
                'notes' => ['label' => 'Note interne', 'required' => false],
            ],
            'actions' => [
                ['action' => 'wait_for', 'selector' => 'form'],
                ['action' => 'type', 'selector' => 'input:is([name*="company" i],[name*="ragione" i])', 'field' => 'company'],
                ['action' => 'type', 'selector' => 'input:is([name*="indir" i],[placeholder*="indirizzo" i])', 'field' => 'address'],
                ['action' => 'type', 'selector' => 'input:is([name*="cap" i],[placeholder*="cap" i])', 'field' => 'cap'],
                ['action' => 'type', 'selector' => 'input:is([name*="comune" i],[placeholder*="comune" i])', 'field' => 'city'],
                ['action' => 'type', 'selector' => 'textarea', 'field' => 'notes'],
                ['action' => 'click', 'selector' => 'button:is([type="submit"],[data-action*="invia" i])', 'scroll' => true],
                ['action' => 'wait_for', 'selector' => 'div[class*="result" i], div[class*="success" i], body', 'timeout' => 35],
                ['action' => 'extract_text', 'selector' => 'div[class*="result" i], div[class*="success" i], body', 'as' => 'esito'],
            ],
        ],
    ];

    /**
     * @return array<int,array{
     *     key:string,
     *     label:string,
     *     url:string,
     *     category:string,
     *     automation_status:string,
     *     notes?:string,
     *     fields:array<string,array{label:string,required:bool}>
     * }>
     */
    public function all(): array
    {
        return array_values(self::PROVIDERS);
    }

    /**
     * @return array<int,array{
     *     key:string,
     *     label:string,
     *     category:string,
     *     automation_status:string,
     *     notes?:string,
     *     fields:array<string,array{label:string,required:bool}>
     * }>
     */
    public function publicMetadata(): array
    {
        return array_values(array_map(static function (array $provider): array {
            return [
                'key' => $provider['key'],
                'label' => $provider['label'],
                'url' => $provider['url'],
                'category' => $provider['category'],
                'automation_status' => $provider['automation_status'],
                'notes' => $provider['notes'] ?? null,
                'fields' => $provider['fields'],
            ];
        }, self::PROVIDERS));
    }

    /**
     * @return array{
     *     key:string,
     *     label:string,
     *     url:string,
     *     category:string,
     *     automation_status:string,
     *     fields:array<string,array{label:string,required:bool}>,
     *     actions:array<int,array<string,mixed>>,
     *     notes?:string
     * }
     */
    public function get(string $key): array
    {
        $normalized = strtolower(trim($key));
        if ($normalized === '' || !isset(self::PROVIDERS[$normalized])) {
            throw new InvalidArgumentException('Gestore non supportato.');
        }

        return self::PROVIDERS[$normalized];
    }
}
