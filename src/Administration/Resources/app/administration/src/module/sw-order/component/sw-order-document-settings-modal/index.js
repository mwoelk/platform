import template from './sw-order-document-settings-modal.html.twig';
import './sw-order-document-settings-modal.scss';

const { Mixin, Utils } = Shopware;
const { isEmpty } = Utils.types;
const { Criteria } = Shopware.Data;

// eslint-disable-next-line sw-deprecation-rules/private-feature-declarations
export default {
    template,

    inject: ['numberRangeService', 'feature', 'repositoryFactory'],

    mixins: [
        Mixin.getByName('notification'),
    ],

    props: {
        order: {
            type: Object,
            required: true,
        },
        currentDocumentType: {
            type: Object,
            required: true,
        },
        isLoadingDocument: {
            type: Boolean,
            required: true,
        },
        isLoadingPreview: {
            type: Boolean,
            required: true,
        },
    },

    data() {
        return {
            showModal: false,
            selectedDocumentFile: null,
            uploadDocument: false,
            documentConfig: {
                custom: {},
                documentNumber: 0,
                documentComment: '',
                documentDate: '',
            },
            documentNumberPreview: false,
            features: {
                uploadFileSizeLimit: 52428800,
                // @feature-deprecated (flag:FEATURE_NEXT_7530) tag:v6.5.0 - Will be removed, use fileAcceptTypes
                fileTypes: ['application/pdf'],
                fileAcceptTypes: 'application/pdf',
            },
            showMediaModal: false,
            deepLinkCode: null,
        };
    },

    computed: {
        documentPreconditionsFulfilled() {
            // can be overwritten in extending component
            return true;
        },

        modalTitle() {
            if (this.feature.isActive('FEATURE_NEXT_7530') && this.currentDocumentType) {
                const documentTypeName = this.currentDocumentType?.translated?.name || this.currentDocumentType?.name;
                return `${this.$tc('sw-order.documentModal.modalTitle')} - ${documentTypeName}`;
            }

            return this.$tc('sw-order.documentModal.modalTitle');
        },

        orderRepository() {
            return this.repositoryFactory.create('order');
        },

        orderCriteria() {
            const criteria = new Criteria(1, 1);

            criteria.addAssociation('lineItems');

            return criteria;
        },

        mediaRepository() {
            return this.repositoryFactory.create('media');
        },

        invoices() {
            return this.order.documents
                .filter(document => {
                    return document.documentType.technicalName === 'invoice';
                });
        },
    },

    created() {
        this.createdComponent();
    },

    methods: {
        async createdComponent() {
            this.documentConfig.documentNumber = await this.reserveDocumentNumber(true);
            this.documentNumberPreview = this.documentConfig.documentNumber;
            this.documentConfig.documentDate = (new Date()).toISOString();
        },

        async onCreateDocument(additionalAction = false) {
            this.$emit('loading-document');

            if (this.documentNumberPreview === this.documentConfig.documentNumber) {
                const documentNumber = await this.reserveDocumentNumber(false);

                if (documentNumber !== this.documentConfig.documentNumber) {
                    this.createNotificationInfo({
                        message: this.$tc('sw-order.documentCard.info.DOCUMENT__NUMBER_WAS_CHANGED'),
                    });
                }

                this.documentConfig.documentNumber = documentNumber;
            }

            await this.addAdditionalInformationToDocument();
            this.callDocumentCreate(additionalAction);
        },

        callDocumentCreate(additionalAction, referencedDocumentId = null) {
            this.$emit(
                'document-create',
                this.documentConfig,
                additionalAction,
                referencedDocumentId,
                (this.uploadDocument ? this.selectedDocumentFile : null),
            );
        },

        async reserveDocumentNumber(isPreview) {
            const { number } = await this.numberRangeService.reserve(
                `document_${this.currentDocumentType.technicalName}`,
                this.order.salesChannelId,
                isPreview,
            );

            return number;
        },

        addAdditionalInformationToDocument() {
            // override in specific document-settings-modals to add additional data to your document
        },

        onPreview() {
            this.$emit('preview-show', this.documentConfig, this.deepLinkCode);
        },

        onConfirm() {
            this.$emit('page-leave-confirm');
        },

        onCancel() {
            this.$emit('page-leave');
        },

        openMediaModal() {
            this.showMediaModal = true;
        },

        closeMediaModal() {
            this.showMediaModal = false;
        },

        async onAddMediaFromLibrary(media) {
            if (isEmpty(media)) {
                return;
            }

            this.validateFile(media[0]);
        },

        successfulUploadFromUrl(res) {
            this.mediaRepository.get(res.targetId).then(response => {
                this.validateFile(response);
            });
        },

        validateFile(response) {
            if (this.$refs.fileInput.checkFileSize(response) && this.$refs.fileInput.checkFileType(response)) {
                this.selectedDocumentFile = response;
                this.documentConfig.documentMediaFileId = response.id;
            }
        },

        removeCustomDocument() {
            this.documentConfig.documentMediaFileId = null;
            this.selectedDocumentFile = null;
            this.sourceDocument = null;
        },

        onAddDocument(data) {
            this.selectedDocumentFile = data[0];
        },

        updateDeepLinkCodeByVersionContext(versionContext) {
            return this.orderRepository
                .get(this.order.id, versionContext, this.orderCriteria)
                .then(response => {
                    this.deepLinkCode = response.deepLinkCode;

                    return response;
                });
        },
    },
};
