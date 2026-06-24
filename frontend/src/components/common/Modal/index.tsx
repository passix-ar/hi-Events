import {Modal as MantineModal, ModalProps as MantineModalProps} from "@mantine/core";
import {useMediaQuery} from "@mantine/hooks";
import React from "react";
import classes from "./Modal.module.scss";
import classNames from "classnames";

interface ModalProps {
    heading?: string | React.ReactNode,
    modalHeader?: 'default' | 'branded',
}

export const Modal = (props: MantineModalProps & ModalProps) => {
    const { modalHeader = 'default', size, fullScreen, ...restProps } = props;
    // On phones a constrained "xl" modal leaves form-heavy flows (create event,
    // create ticket, etc.) cramped in a narrow box. Go full screen below md so
    // the form uses the whole viewport. Callers can still override either prop.
    const isMobile = useMediaQuery('(max-width: 767px)');
    return (
        <MantineModal
            {...restProps}
            overlayProps={{
                opacity: 0.55,
                blur: 3,
            }}
            size={size ?? 'xl'}
            fullScreen={fullScreen ?? isMobile}
            withCloseButton={true}
            title={props.heading}
            closeOnClickOutside={false}
            classNames={{
                title: classNames(
                    classes.modalTitle,
                    modalHeader === 'branded' && classes.brandedTitle
                ),
                header: classNames(
                    modalHeader === 'branded' && classes.brandedHeader
                ),
                close: classNames(
                    modalHeader === 'branded' && classes.brandedClose
                ),
                ...props.classNames
            }}
        >
            <div style={{padding: '15px', paddingTop: 0}}>
                {props.children}
            </div>
        </MantineModal>
    )
}
