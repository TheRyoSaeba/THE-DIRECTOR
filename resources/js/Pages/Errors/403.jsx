import { Head } from '@inertiajs/react';

export default function Error403() {
    return (
        <>
            <Head title="403 - Access Denied" />
            <style>{`
                * {
                    box-sizing: border-box;
                    margin: 0;
                    padding: 0;
                }

                body, html {
                    color: #404040;
                    -webkit-font-smoothing: antialiased;
                    -moz-osx-font-smoothing: grayscale;
                    font-family: system-ui, -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, Helvetica Neue, Arial, Noto Sans, sans-serif;
                    font-size: 16px;
                    margin: 0;
                    padding: 0;
                    background: #eeeeea;
                }

                a {
                    color: #2f7bbf;
                    text-decoration: none;
                    transition: all .15s cubic-bezier(0,0,.2,1);
                }

                a:hover {
                    color: #f68b1f;
                }

                .clearfix:after {
                    content: "";
                    display: table;
                    clear: both;
                }

                .bg-gradient-gray {
                    background-image: linear-gradient(to bottom, #dedede, #ebebeb 3%, #ebebeb 97%, #dedede);
                }

                .cf-error-source:after {
                    position: absolute;
                    background-color: #fff;
                    width: 2.5rem;
                    height: 2.5rem;
                    transform: rotate(45deg);
                    content: "";
                    bottom: -1.75rem;
                    left: 50%;
                    margin-left: -1.25rem;
                    box-shadow: 0 0 4px 4px #dedede;
                }

                @media screen and (max-width: 720px) {
                    .cf-error-source:after {
                        display: none;
                    }
                }

                .cf-icon-browser {
                    background-image: url("data:image/svg+xml;utf8,%3Csvg id='a' xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 80.7362'%3E%3Cpath d='M89.8358.1636H10.1642C4.6398.1636.1614,4.6421.1614,10.1664v60.4033c0,5.5244,4.4784,10.0028,10.0028,10.0028h79.6716c5.5244,0,10.0027-4.4784,10.0027-10.0028V10.1664c0-5.5244-4.4784-10.0028-10.0027-10.0028ZM22.8323,9.6103c1.9618,0,3.5522,1.5903,3.5522,3.5521s-1.5904,3.5522-3.5522,3.5522-3.5521-1.5904-3.5521-3.5522,1.5903-3.5521,3.5521-3.5521ZM12.8936,9.6103c1.9618,0,3.5522,1.5903,3.5522,3.5521s-1.5904,3.5522-3.5522,3.5522-3.5521-1.5904-3.5521-3.5522,1.5903-3.5521,3.5521-3.5521ZM89.8293,70.137H9.7312V24.1983h80.0981v45.9387ZM89.8293,16.1619H29.8524v-5.999h59.977v5.999Z' style='fill: %23999;'/%3E%3C/svg%3E");
                }

                .cf-icon-cloud {
                    background-image: url("data:image/svg+xml;utf8,%3Csvg id='a' xmlns='http://www.w3.org/2000/svg' viewBox='0 0 152 78.9141'%3E%3Cpath d='M132.2996,77.9927v-.0261c10.5477-.2357,19.0305-8.8754,19.0305-19.52,0-10.7928-8.7161-19.5422-19.4678-19.5422-2.9027,0-5.6471.6553-8.1216,1.7987C123.3261,18.6624,105.3419.9198,83.202.9198c-17.8255,0-32.9539,11.5047-38.3939,27.4899-3.0292-2.2755-6.7818-3.6403-10.8622-3.6403-10.0098,0-18.1243,8.1145-18.1243,18.1243,0,1.7331.258,3.4033.7122,4.9905-.2899-.0168-.5769-.0442-.871-.0442-8.2805,0-14.993,6.7503-14.993,15.0772,0,8.2795,6.6381,14.994,14.8536,15.0701v.0054h.1069c.0109,0,.0215.0016.0325.0016s.0215-.0016.0325-.0016' style='fill: %23999;'/%3E%3C/svg%3E");
                }

                .cf-icon-server {
                    background-image: url("data:image/svg+xml;utf8,%3Csvg id='a' xmlns='http://www.w3.org/2000/svg' viewBox='0 0 95 75'%3E%3Cpath d='M94.0103,45.0775l-12.9885-38.4986c-1.2828-3.8024-4.8488-6.3624-8.8618-6.3619l-49.91.0065c-3.9995.0005-7.556,2.5446-8.8483,6.3295L1.0128,42.8363c-.3315.971-.501,1.9899-.5016,3.0159l-.0121,19.5737c-.0032,5.1667,4.1844,9.3569,9.3513,9.3569h75.2994c5.1646,0,9.3512-4.1866,9.3512-9.3512v-17.3649c0-1.0165-.1657-2.0262-.4907-2.9893ZM86.7988,65.3097c0,1.2909-1.0465,2.3374-2.3374,2.3374H9.9767c-1.2909,0-2.3374-1.0465-2.3374-2.3374v-18.1288c0-1.2909,1.0465-2.3374,2.3374-2.3374h74.4847c1.2909,0,2.3374,1.0465,2.3374,2.3374v18.1288Z' style='fill: %23999;'/%3E%3Ccircle cx='74.6349' cy='56.1889' r='4.7318' style='fill: %23999;'/%3E%3Ccircle cx='59.1472' cy='56.1889' r='4.7318' style='fill: %23999;'/%3E%3C/svg%3E");
                }

                .cf-icon-ok {
                    background-image: url("data:image/svg+xml;utf8,%3Csvg id='a' xmlns='http://www.w3.org/2000/svg' viewBox='0 0 48 48'%3E%3Ccircle cx='24' cy='24' r='23.4815' style='fill: %239bca3e;'/%3E%3Cpolyline points='17.453 24.9841 21.7183 30.4504 30.2076 16.8537' style='fill: none; stroke: %23fff; stroke-linecap: round; stroke-linejoin: round; stroke-width: 4px;'/%3E%3C/svg%3E");
                }

                .cf-icon-error {
                    background-image: url("data:image/svg+xml;utf8,%3Csvg id='a' xmlns='http://www.w3.org/2000/svg' viewBox='0 0 47.9145 47.9641'%3E%3Ccircle cx='23.9572' cy='23.982' r='23.4815' style='fill: %23bd2426;'/%3E%3Cline x1='19.0487' y1='19.0768' x2='27.8154' y2='28.8853' style='fill: none; stroke: %23fff; stroke-linecap: round; stroke-linejoin: round; stroke-width: 3px;'/%3E%3Cline x1='27.8154' y1='19.0768' x2='19.0487' y2='28.8853' style='fill: none; stroke: %23fff; stroke-linecap: round; stroke-linejoin: round; stroke-width: 3px;'/%3E%3C/svg%3E");
                }

                .code-label {
                    background-color: #d9d9d9;
                    color: #313131;
                    font-weight: 500;
                    border-radius: 1.25rem;
                    font-size: .75rem;
                    line-height: 1;
                    padding: .5rem .75rem;
                    white-space: nowrap;
                    vertical-align: middle;
                }
            `}</style>

            <div id="cf-wrapper">
                <div id="cf-error-details" style={{ padding: 0 }}>
                    <header style={{ maxWidth: '60rem', margin: '0 auto', paddingTop: '2.5rem', paddingLeft: '1rem', paddingRight: '1rem', marginBottom: '2rem' }}>
                        <h1 style={{ display: 'inline-block', fontWeight: 300, fontSize: '60px', color: '#404040', lineHeight: 1.25, marginRight: '.5rem' }}>
                            <span style={{ display: 'inline-block' }}>Admin@TheDirector.app cannot find this page for you.</span>{' '}
                            <span className="code-label">Error code 403</span>
                        </h1>
                        <div style={{ marginTop: '.75rem' }}>
                        </div>
                        <div style={{ marginTop: '.75rem', fontSize: '15px' }}>{new Date().toUTCString()}</div>
                    </header>

                    <div style={{ marginTop: '2rem', marginBottom: '2rem' }} className="bg-gradient-gray">
                        <div style={{ maxWidth: '60rem', margin: '0 auto' }}>
                            <div className="clearfix">
                                <div className="cf-error-source" style={{ position: 'relative', width: '33.333333%', paddingTop: '3.75rem', paddingBottom: '3.75rem', overflow: 'hidden', float: 'left', textAlign: 'center' }}>
                                    <div style={{ position: 'relative', marginBottom: '2.5rem' }}>
                                        <span className="cf-icon-browser" style={{ display: 'block', height: '5rem', backgroundPosition: '50%', backgroundRepeat: 'no-repeat' }}></span>
                                        <span className="cf-icon-ok" style={{ display: 'inline-block', width: '3rem', height: '3rem', position: 'absolute', left: '50%', marginLeft: '-1.5rem', bottom: '-1rem', backgroundPosition: '50%', backgroundRepeat: 'no-repeat' }}></span>
                                    </div>
                                    <span style={{ display: 'block', width: '100%', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>You</span>
                                    <h3 style={{ marginTop: '.75rem', fontSize: '1.5rem', color: '#999', fontWeight: 300, lineHeight: 1.3 }}>Browser</h3>
                                    <span style={{ lineHeight: 1.3, fontSize: '1.5rem', color: '#9bca3e' }}>Working</span>
                                </div>

                                <div className="cf-error-source" style={{ position: 'relative', width: '33.333333%', paddingTop: '3.75rem', paddingBottom: '3.75rem', overflow: 'hidden', float: 'left', textAlign: 'center' }}>
                                    <div style={{ position: 'relative', marginBottom: '2.5rem' }}>
                                        <span className="cf-icon-cloud" style={{ display: 'block', height: '5rem', backgroundPosition: '50%', backgroundRepeat: 'no-repeat' }}></span>
                                        <span className="cf-icon-error" style={{ display: 'inline-block', width: '3rem', height: '3rem', position: 'absolute', left: '50%', marginLeft: '-1.5rem', bottom: '-1rem', backgroundPosition: '50%', backgroundRepeat: 'no-repeat' }}></span>
                                    </div>
                                    <span style={{ display: 'block', width: '100%', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>Your Location</span>
                                    <h3 style={{ marginTop: '.75rem', fontSize: '1.5rem', color: '#2f7bbf', fontWeight: 300, lineHeight: 1.3 }}>Cloudflare</h3>
                                    <span style={{ lineHeight: 1.3, fontSize: '1.5rem', color: '#bd2426' }}>Error</span>
                                </div>

                                <div className="cf-error-source" style={{ position: 'relative', width: '33.333333%', paddingTop: '3.75rem', paddingBottom: '3.75rem', overflow: 'hidden', float: 'left', textAlign: 'center' }}>
                                    <div style={{ position: 'relative', marginBottom: '2.5rem' }}>
                                        <span className="cf-icon-server" style={{ display: 'block', height: '5rem', backgroundPosition: '50%', backgroundRepeat: 'no-repeat' }}></span>
                                        <span className="cf-icon-ok" style={{ display: 'inline-block', width: '3rem', height: '3rem', position: 'absolute', left: '50%', marginLeft: '-1.5rem', bottom: '-1rem', backgroundPosition: '50%', backgroundRepeat: 'no-repeat' }}></span>
                                    </div>
                                    <span style={{ display: 'block', width: '100%', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>Website</span>
                                    <h3 style={{ marginTop: '.75rem', fontSize: '1.5rem', color: '#999', fontWeight: 300, lineHeight: 1.3 }}>TheDirector.app</h3>
                                    <span style={{ lineHeight: 1.3, fontSize: '1.5rem', color: '#9bca3e' }}>Working</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div style={{ maxWidth: '60rem', margin: '0 auto', marginBottom: '2rem', paddingLeft: '1rem', paddingRight: '1rem' }}>
                        <div className="clearfix">
                            <div style={{ width: '50%', float: 'left', paddingRight: '1.5rem', lineHeight: 1.625 }}>
                                <h2 style={{ fontSize: '1.875rem', fontWeight: 400, lineHeight: 1.3, marginBottom: '1rem' }}>What happened?</h2>
                                <p style={{ fontSize: '15px', lineHeight: 1.5 }}>The page you're looking for doesn't exist and is unmoved.</p>
                            </div>
                            <div style={{ width: '50%', float: 'left', lineHeight: 1.625 }}>
                                <h2 style={{ fontSize: '1.875rem', fontWeight: 400, lineHeight: 1.3, marginBottom: '1rem' }}>What can I do?</h2>
                                <p style={{ fontSize: '15px', lineHeight: 1.5 }}>If you believe this is an error, <a href="/">return to homepage</a> or contact support.</p>
                            </div>
                        </div>
                    </div>

                    <div style={{ maxWidth: '60rem', margin: '0 auto', paddingTop: '2.5rem', paddingBottom: '2.5rem', paddingLeft: '1rem', paddingRight: '1rem', textAlign: 'center', borderTop: '1px solid #ebebeb' }}>
                        <p style={{ fontSize: '13px', color: '#999' }}>
                            Performance &amp; security by <a rel="noopener noreferrer" href="https://www.cloudflare.com/" target="_blank">Cloudflare</a>
                        </p>
                    </div>
                </div>
            </div>
        </>
    );
}
