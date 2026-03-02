<?php
/**
 * Class Dashboard_Widget file.
 *
 * @package FazCookie
 */

namespace FazCookie\Admin\Modules\Dashboard_Widget;

use FazCookie\Includes\Modules;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Handles Dashboard Widget Operation
 *
 * @class       Dashboard_Widget
 * @version     3.0.0
 * @package     FazCookie
 */
class Dashboard_Widget extends Modules {
    /**
     * Endpoint namespace.
     *
     * @var string
     */
    protected $namespace = 'faz/v1';

    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = '/dashboard-widget';

    /**
     * Instance of the current class
     *
     * @var object
     */
    private static $instance;

    /**
     * Return the current instance of the class
     *
     * @return object
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct('dashboard_widget');
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
    }

    /**
     * Enqueue required scripts
     */
    public function enqueue_scripts( $hook ) {
        if ( 'index.php' !== $hook ) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }

        $script_suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
        wp_enqueue_script(
            'faz-chart',
            plugin_dir_url( __FILE__ ) . 'assets/js/chart' . $script_suffix . '.js',
            array(),
            '4.4.1',
            true
        );
    }

    /**
     * Initialize the class
     */
    public function init() {
        add_action( 'wp_dashboard_setup', array( $this, 'add_fazcookie_dashboard_widget' ) );
    }

    /**
     * Add the FAZ Cookie Manager dashboard widget.
     */
    public function add_fazcookie_dashboard_widget() {
        if (!current_user_can('manage_options')) {
            return;
        }
        wp_add_dashboard_widget(
            'fazcookie_dashboard_widget',
            __( 'FAZ Cookie Manager', 'faz-cookie-manager' ),
            array( $this, 'render_fazcookie_dashboard_widget' )
        );
    }

    /**
     * Render the FAZ Cookie Manager dashboard widget.
     */
    public function render_fazcookie_dashboard_widget() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $connected = false;
        if ( class_exists( 'FazCookie\\Admin\\Modules\\Settings\\Includes\\Settings' ) ) {
            $settings = \FazCookie\Admin\Modules\Settings\Includes\Settings::get_instance();
            $connected = ! empty( $settings->get_website_id() );
        } else {
            $connected = get_option( 'faz_webapp_connected' );
        }

        if ( ! $connected ) {
            $this->render_dashboard_widget_disconnected();
        } else {
            $this->render_dashboard_widget_connected();
        }
    }

    /**
     * Render the widget for disconnected state.
     */
    private function render_dashboard_widget_disconnected() {
        ?>
        <div class="faz-consent-chart-section">
            <div class="faz-consent-chart faz-blur">
                <img 
                    src="<?php echo esc_url( FAZ_PLUGIN_URL . 'admin/dist/img/trends.png' ); ?>" 
                    alt="Consent Trends Dummy Chart"
                    style="display:block;width:100%;height:auto;"
                />
                <div class="faz-modal-overlay">
                    <div class="faz-modal-content">
                        <b><?php esc_html_e( 'Get cookie consent insights in your Dashboard!', 'faz-cookie-manager' ); ?></b>
                        <p><?php esc_html_e( 'Track your consent rates and unlock advanced features that keep your site in check.', 'faz-cookie-manager' ); ?></p>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=faz-cookie-manager' ) ); ?>" class="button button-primary">
                            <?php esc_html_e( 'Go to FAZ Cookie Manager Settings', 'faz-cookie-manager' ); ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <style>
            .faz-consent-chart-section {
                position: relative;
                min-height: 250px;
                margin: 0px -12px -12px -12px;
            }
            .faz-modal-overlay {
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 10;
            }
            .faz-modal-content {
                background: #fff;
                border-radius: 16px;
                box-shadow: 0px 2px 12.3px 0px rgba(114, 174, 230, 0.25);
                padding: 32px 8px;
                max-width: 350px;
                text-align: center;
                z-index: 11;
            }
            .faz-modal-content b {
                display: block;
                margin-bottom: 12px;
                font-size: 16px;
                font-weight: 600;
                line-height: 24px;
            }
            .faz-modal-content p {
                font-size: 13px;
                font-weight: 400;
                line-height: 18px;
                margin-bottom: 20px;
            }
            .faz-modal-content .button.button-primary {
                padding: 8px 16px;
                line-height: normal;
                font-size: 15px;
            }
        </style>
        <?php
    }

    /**
     * Render the widget for connected state.
     */
    private function render_dashboard_widget_connected() {
        ?>
        <div class="faz-consent-chart-widget" id="faz-dashboard-widget-chart">
            <div class="faz-chart-container" id="faz-dashboard-widget-chart-container">
                <div id="faz-chart-loader" class="faz-card-loader-container" style="display:flex;">
                    <div class="faz-card-loader">
                        <div class="faz-card-loader--line"></div>
                        <div class="faz-card-loader--line"></div>
                        <div class="faz-card-loader--line"></div>
                        <div class="faz-card-loader--line"></div>
                        <div class="faz-card-loader--line faz-card-loader--rect"></div>
                    </div>
                </div>
                <canvas id="faz-pie-chart-widget" width="320" height="320" style="display:none;width:100%;height:auto;"></canvas>
                <div class="faz-center-total-consents" style="display:none;">
                    <span class="faz-center-total-consents-value"></span>
                    <div class="faz-center-total-consents-label">Total Consents</div>
                </div>
                <div id="faz-no-consents-placeholder" style="display:none;text-align:center;padding:40px 0;">
                    <svg width="110" height="110" viewBox="0 0 110 110" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="55" cy="55" r="55" fill="#E5E7EA"/>
                        <circle cx="38" cy="54" r="9" fill="#FFFFFF"/>
                        <circle cx="55" cy="80" r="5" fill="#FFFFFF"/>
                        <circle cx="75" cy="45" r="11" fill="#FFFFFF"/>
                        <circle cx="55" cy="30" r="5" fill="#FFFFFF"/>
                        
                    </svg>
                    <p style="font-size:20px;color:#656178;margin-top:20px;">No consents were logged</p>
                </div>
                <div id="faz-api-error-placeholder" style="display:none;text-align:center;padding:40px 0;">
                    <svg width="110" height="110" viewBox="0 0 110 110" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="55" cy="55" r="55" fill="#E5E7EA"/>
                        <circle cx="38" cy="54" r="9" fill="#FFFFFF"/>
                        <circle cx="55" cy="80" r="5" fill="#FFFFFF"/>
                        <circle cx="75" cy="45" r="11" fill="#FFFFFF"/>
                        <circle cx="55" cy="30" r="5" fill="#FFFFFF"/>
                        
                    </svg>
                    <p style="font-size:20px;color:#656178;margin-top:20px;"><?php esc_html_e( 'Unable to load consent trends. Please try again later.', 'faz-cookie-manager' ); ?></p>
                </div>
            </div>
            <div class="faz-consent-legend" id="faz-consent-legend" style="display:none;">
                <div class="faz-legend-item"><span class="faz-legend-color faz-legend-accepted"></span>Accepted</div>
                <div class="faz-legend-item"><span class="faz-legend-color faz-legend-rejected"></span>Rejected</div>
                <div class="faz-legend-item"><span class="faz-legend-color faz-legend-partial"></span>Partially Accepted</div>
            </div>
        </div>
        <script>
        (function(){
            function initChart() {
                const TIMEOUT_MS = <?php echo esc_js( 180 * 1000 ); ?>;
                let controller = null;
                let timeoutId = null;

                let loading = true;
                let data = [];

                function getCount(consents, type) {
                    let consent = false;
                    if (typeof consents === "object") {
                        consent = consents.find(function (item) {
                            return item.type === type;
                        });
                    }
                    return (consent && consent.count) || 0;
                }

                async function getChartData() {
                    loading = true;
                    data = [];
                    
                    controller = new AbortController();
                    timeoutId = setTimeout(() => controller.abort(), TIMEOUT_MS);

                    try {
                        const response = await fetch('<?php echo esc_url_raw( rest_url() . 'faz/v1/consent_logs/statistics' ); ?>', {
                            method: 'GET',
                            headers: {
                                'X-WP-Nonce': '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>'
                            },
                            signal: controller.signal
                        });
                        clearTimeout(timeoutId);

                        if (!response.ok) {
                            throw new Error('API request failed with status: ' + response.status);
                        }

                        data = await response.json();

                        if (data.length <= 0) {
                            loading = false;
                            const loader = document.getElementById('faz-chart-loader');
                            if (loader) {
                                loader.style.display = 'none';
                            }
                            document.getElementById('faz-no-consents-placeholder').style.display = 'block';
                            return;
                        }

                        let responseArr = [getCount(data, "accepted"), getCount(data, "rejected"), getCount(data, "partial")];
                        const total = responseArr.reduce((a, b) => a + b, 0);

                        if (total === 0) {
                            loading = false;
                            const loader = document.getElementById('faz-chart-loader');
                            if (loader) {
                                loader.style.display = 'none';
                            }
                            document.getElementById('faz-no-consents-placeholder').style.display = 'block';
                            return;
                        }

                        if (responseArr && responseArr.length > 0) {
                            const loader = document.getElementById('faz-chart-loader');
                            if (loader) {
                                loader.style.display = 'none';
                            }
                            document.getElementById('faz-pie-chart-widget').style.display = 'block';
                            document.querySelector('.faz-center-total-consents').style.display = 'block';
                            document.getElementById('faz-consent-legend').style.display = 'flex';
                            document.querySelector('.faz-center-total-consents-value').textContent = total;

                            const ctx = document.getElementById('faz-pie-chart-widget').getContext('2d');
                            new window.Chart(ctx, {
                                type: 'doughnut',
                                data: {
                                    labels: ['Accepted', 'Rejected', 'Partially Accepted'],
                                    datasets: [{
                                        data: responseArr,
                                        backgroundColor: ['rgba(51, 168, 129, 0.5)', 'rgba(236, 74, 94, 0.5)', 'rgba(68, 147, 249, 0.5)'],
                                        borderWidth: 0
                                    }]
                                },
                                options: {
                                    cutout: '80%',
                                    plugins: {
                                        legend: { display: false },
                                        tooltip: {
                                            enabled: true,
                                            backgroundColor: '#656178',
                                            titleFont: {
                                                size: 14,
                                                weight: 'bold'
                                            },
                                            bodyFont: {
                                                size: 13
                                            },
                                            padding: 12,
                                            cornerRadius: 8,
                                            displayColors: false,
                                            callbacks: {
                                                label: function(context) {
                                                    const value = context.raw;
                                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                                    const percentage = Math.round((value / total) * 100);
                                                    return `${context.label}: ${value} (${percentage}%)`;
                                                }
                                            }
                                        }
                                    },
                                    responsive: true,
                                    maintainAspectRatio: false
                                }
                            });
                        }
                    } catch (e) {
                        if (timeoutId) {
                            clearTimeout(timeoutId);
                        }
                        console.error(e);
                        const loader = document.getElementById('faz-chart-loader');
                        if (loader) {
                            loader.style.display = 'none';
                        }
                        const chartContainer = document.getElementById('faz-dashboard-widget-chart-container');
                        const noConsentsPlaceholder = document.getElementById('faz-no-consents-placeholder');
                        const apiErrorPlaceholder = document.getElementById('faz-api-error-placeholder');
                        const consentLegend = document.getElementById('faz-consent-legend');
                        
                        if (chartContainer) {
                            const chartCanvas = document.getElementById('faz-pie-chart-widget');
                            const centerTotal = document.querySelector('.faz-center-total-consents');
                            if (chartCanvas) {
                                chartCanvas.style.display = 'none';
                            }
                            if (centerTotal) {
                                centerTotal.style.display = 'none';
                            }
                            if (noConsentsPlaceholder) {
                                noConsentsPlaceholder.style.display = 'none';
                            }
                            if (consentLegend) {
                                consentLegend.style.display = 'none';
                            }
                            if (apiErrorPlaceholder) {
                                apiErrorPlaceholder.style.display = 'block';
                            }
                        }
                    }

                    loading = false;
                }

                getChartData();
            }

            if (document.readyState === 'complete') {
                initChart();
            } else {
                window.addEventListener('load', initChart);
            }
        })();
        </script>
        <style>
    .faz-consent-chart-widget {
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 380px;
    }
    .faz-chart-container {
        position: relative;
        width: 320px;
        height: 320px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto;
    }
    #faz-pie-chart-widget {
        position: absolute;
        width: 100% !important;
        height: auto !important;
    }
    .faz-center-total-consents {
        position: absolute;
        left: 0;
        right: 0;
        top: 0;
        bottom: 0;
        margin: auto;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-align: center;
        pointer-events: none;
        z-index: 1;
        height: fit-content;
        width: fit-content;
    }
    .faz-center-total-consents-value {
        font-size: 4em;
        font-weight: 700;
        line-height: 1;
        color: #111;
        display: block;
        margin-bottom: 5px;
    }
    .faz-center-total-consents-label {
        font-size: 1.5em;
        font-weight: 400;
        color: #111;
        white-space: nowrap;
    }
    .faz-consent-legend {
    margin-left: 48px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 50px;
}
    .faz-legend-item {
        display: flex;
        align-items: center;
    }
    .faz-legend-color {
        display: inline-block;
        width: 20px;
        height: 20px;
        margin-right: 10px;
        flex-shrink: 0;
    }
    .faz-legend-accepted {
        background: rgba(51, 168, 129, 0.5);
    }
    .faz-legend-rejected {
        background: rgba(236, 74, 94, 0.5);
    }
    .faz-legend-partial {
        background: rgba(68, 147, 249, 0.5);
    }
    .faz-card-loader-container {
        display: flex;
        align-items: center;
        position: absolute;
        z-index: 12;
        top: 0;
        left: 0;
        right: 0;
        width: 100%;
        height: 100%;
        padding: 15px;
        min-height: 114px;
    }
    .faz-card-loader {
        width: 100%;
    }
    .faz-card-loader--line {
        opacity: 0.5;
        background: #f6f7f8;
        background: linear-gradient(to right, #d7e1f2 8%, #c1d1eb 18%, #d7e1f2 33%);
        background-size: 800px 100px;
        border-radius: 3px;
        animation-duration: 1s;
        animation-fill-mode: forwards;
        animation-iteration-count: infinite;
        animation-name: faz-shimmer;
        animation-timing-function: linear;
        height: 10px;
        width: 100%;
    }
    .faz-card-loader--line:nth-child(1) {
        width: 70%;
        height: 15px;
    }
    .faz-card-loader--line:nth-child(2) {
        width: 60%;
    }
    .faz-card-loader--line:not(:first-child) {
        margin-top: 6px;
    }
    .faz-card-loader--rect {
        min-height: 35px;
    }
    @keyframes faz-shimmer {
        0% {
            background-position: -400px 0;
        }
        100% {
            background-position: 400px 0;
        }
    }
</style> 
        <?php
    }
}

// Initialize the widget module
Dashboard_Widget::get_instance()->init(); 