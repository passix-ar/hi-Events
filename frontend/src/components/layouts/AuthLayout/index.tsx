import {Navigate, Outlet} from "react-router";
import classes from "./Auth.module.scss";
import {t} from "@lingui/macro";
import {useGetMe} from "../../../queries/useGetMe.ts";
import {PoweredByFooter} from "../../common/PoweredByFooter";
import {LanguageSwitcher} from "../../common/LanguageSwitcher";
import {
    IconCalendarPlus,
    IconCreditCard,
    IconQrcode,
} from '@tabler/icons-react';
import {useCallback, useRef} from "react";
import {getConfig} from "../../../utilites/config.ts";
import {isHiEvents, getUserHomePath} from "../../../utilites/helpers.ts";
import {showInfo} from "../../../utilites/notifications.tsx";

const benefits = [
    {
        icon: IconCalendarPlus,
        title: t`Creá tu evento en minutos`,
        description: t`Elegí si las entradas son pagas, gratuitas o con donaciones. Cambiá precios cuando quieras y empezá a vender al instante.`,
    },
    {
        icon: IconCreditCard,
        title: t`Vendé sin complicarte`,
        description: t`Cobrás directamente con Mercado Pago. El dinero va a tu cuenta y vos mantenés el control de todo.`,
    },
    {
        icon: IconQrcode,
        title: t`El ingreso fluye solo`,
        description: t`Escaneá las entradas desde cualquier celular y seguí el ingreso en tiempo real. Menos filas, menos problemas, más tranquilidad.`,
    },
];

const FeaturePanel = () => {
    return (
        <div className={classes.rightPanel}>
            <div className={classes.backgroundImage} />
            <div className={classes.backgroundOverlay} />
            <div className={classes.gridPattern} />
            <div className={`${classes.glowEffect} ${classes.glowTop}`} />
            <div className={`${classes.glowEffect} ${classes.glowBottom}`} />

            <div className={classes.overlay}>
                <div className={classes.content}>
                    <span className={classes.eyebrow}>{t`Empezá gratis`}</span>
                    <h2 className={classes.headline}>
                        {t`Vos armás el evento. Nosotros hacemos que todo funcione.`}
                    </h2>
                    <p className={classes.subtitle}>
                        {t`Passix está pensado para organizadores de Argentina. Desde que publicás el evento hasta que entra la última persona, te acompañamos para que todo salga como esperás.`}
                    </p>

                    <div className={classes.featureGrid}>
                        {benefits.map((feature, index) => {
                            const Icon = feature.icon;
                            return (
                                <div key={index} className={classes.feature}>
                                    <div className={classes.featureIcon}>
                                        <Icon size={18} />
                                    </div>
                                    <div className={classes.featureText}>
                                        <h3>{feature.title}</h3>
                                        <p>{feature.description}</p>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>
            </div>
        </div>
    );
};

const AuthLayout = () => {
    const me = useGetMe();
    const clickCountRef = useRef(0);
    const clickTimerRef = useRef<ReturnType<typeof setTimeout>>();

    const handleLogoClick = useCallback(() => {
        clickCountRef.current += 1;
        clearTimeout(clickTimerRef.current);
        clickTimerRef.current = setTimeout(() => { clickCountRef.current = 0; }, 2000);

        if (clickCountRef.current >= 5) {
            clickCountRef.current = 0;
            showInfo(`Passix v${__APP_VERSION__}`);
        }
    }, []);

    if (me.isPending) {
        return (
            <div style={{
                minHeight: '100vh',
                background: '#0b0b0e',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
            }}>
                <div style={{
                    width: 32,
                    height: 32,
                    borderRadius: '50%',
                    border: '3px solid #26262f',
                    borderTopColor: '#d6ff3d',
                    animation: 'auth-spin 0.7s linear infinite',
                }} />
                <style>{`@keyframes auth-spin { to { transform: rotate(360deg); } }`}</style>
            </div>
        );
    }

    if (me.isSuccess) {
        return <Navigate to={getUserHomePath(me.data)} />;
    }

    return (
        <div className={classes.authLayout}>
            <div className={classes.splitLayout}>
                <div className={classes.leftPanel}>
                    <main className={classes.container}>
                        <div className={classes.logo} onClick={handleLogoClick} style={{cursor: 'pointer'}}>
                            <img
                                className={classes.logoMark}
                                src={"/logos/passix-mark.png?v=3"}
                                alt=""
                                aria-hidden="true"
                            />
                            <img
                                className={classes.logoWordmark}
                                src={"/logos/passix-dark-bg.svg?v=3"}
                                alt={t`${getConfig("VITE_APP_NAME", "Passix")} logo`}
                            />
                        </div>
                        <div className={classes.wrapper}>
                            <Outlet />
                            {/*
                             * (c) Hi.Events Ltd 2025
                             *
                             * PLEASE NOTE:
                             *
                             * Hi.Events is licensed under the GNU Affero General Public License (AGPL) version 3.
                             *
                             * You can find the full license text at: https://github.com/HiEventsDev/hi.events/blob/main/LICENCE
                             *
                             * In accordance with Section 7(b) of the AGPL, we ask that you retain the "Powered by Hi.Events" notice.
                             *
                             * If you wish to remove this notice, a commercial license is available at: https://hi.events/licensing
                             */}
                            {!isHiEvents() && <PoweredByFooter />}
                            <div className={classes.languageSwitcher}>
                                <LanguageSwitcher />
                            </div>
                        </div>
                    </main>
                </div>

                <FeaturePanel />
            </div>
        </div>
    );
};

export default AuthLayout;
