/**
 * Client logic for the WISY-KI feature "Weiterbildungsscoiut".
 *
 * A step-by-step guided process to assess the skills of the user and their lerning goals
 * for the purpose of making well fitting skill-based course recommendations.
 *
 * @copyright   2023 ISy TH Lübeck <dev.ild@th-luebeck.de>
 * @author		Pascal Hürten <pascal.huerten@th-luebeck.de>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

window.onload = main;

/**
 * Scout controller.
 * @type {Scout}
 */
let scout;

async function main() {
    // Set CSS Property docHeight according to the current document height.
    addEventListener("resize", setCSSPropertyDocHeight);
    setCSSPropertyDocHeight();

    window.addEventListener("popstate", function(event) {
        // Handle the URL change here
        // You can access the updated URL using event.target.location
        // Reload the page if the step attribute is diffrent from the current step.
        // Get step from URL.
        const urlParams = new URLSearchParams(window.location.search);
        const step = urlParams.get('step');
        console.log(step);
        console.log(scout.currentStep);
        if (step != scout.currentStep) {
            location.reload();
        }
    });
    
    // Detect whether virtualKeyboard is shown on screen.
    if ("visualViewport" in window) {
        window.visualViewport.addEventListener("resize", setVirtualKeyboardStatus);
    }
    
    // TODO: Get lang from user preferneces.
    await Lang.init('de');
    scout = new Scout();
    scout.init();
}