import {t} from '@lingui/macro';
import {GenericErrorPage} from "../../../common/GenericErrorPage";

export const OrganizerNotFound = () => {
    return (
        <GenericErrorPage
            title={t`Organizer Not Found`}
            description={t`The organizer you're looking for could not be found. The page may have been moved, deleted, or the URL might be incorrect.`}
            pageTitle={t`Organizer Not Found`}
            metaDescription={t`The organizer you're looking for could not be found. The page may have been moved, deleted, or the URL might be incorrect.`}
        >

        </GenericErrorPage>
    );
};

export default OrganizerNotFound;
