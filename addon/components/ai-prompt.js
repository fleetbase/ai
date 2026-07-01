import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { tracked } from '@glimmer/tracking';
import { task } from 'ember-concurrency';
import formatAiResponse from '../utils/format-ai-response';

export default class AiPromptComponent extends Component {
    @service ai;
    @service aiEvents;
    @service fetch;
    @service currentUser;
    @service notifications;
    @tracked prompt = '';
    @tracked showHistory = false;
    @tracked attachments = [];
    @tracked uploadQueue = [];
    @tracked responseStackElement = null;
    @tracked isNearResponseBottom = true;
    @tracked showScrollToLatest = false;
    @tracked shouldAutoScroll = true;
    responseScrollFrame = null;

    get shouldShowResponseStack() {
        return this.turns.length > 0;
    }

    get turns() {
        return (this.ai.sessionTasks ?? []).map((task) => this.normalizeTask(task)).filter(Boolean);
    }

    get responseScrollSignature() {
        return this.turns
            .map((task) => {
                const previewSignature = (task.actionPreviews ?? []).map((preview) => `${preview.key ?? preview.action}:${preview.ready}:${preview.isDisabled}`).join(',');

                return [task.uuid ?? task.id, task.status, task.isPending, task.response?.length ?? 0, previewSignature].join(':');
            })
            .join('|');
    }

    get hasCurrentSession() {
        return Boolean(this.ai.currentSession);
    }

    get acceptedAttachmentTypes() {
        return [
            'text/plain',
            'text/csv',
            'application/csv',
            'application/json',
            'application/pdf',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'image/png',
            'image/jpeg',
            'image/webp',
        ].join(',');
    }

    get isUploadingAttachments() {
        return this.uploadAttachment.isRunning || this.uploadQueue.length > 0;
    }

    get isSubmitDisabled() {
        return this.ai.submit.isRunning || this.isUploadingAttachments;
    }

    normalizeTask(task) {
        if (!task) {
            return null;
        }

        const response = typeof task?.response === 'string' ? task.response.trim() : task?.response;
        const actionPreviews = this.normalizedActionPreviewsFor(task);
        const actionResults = this.actionResultsFor(task);
        const visibleResponse = actionPreviews.length > 0 ? null : response;

        return {
            ...task,
            response: visibleResponse,
            formattedResponse: visibleResponse ? formatAiResponse(visibleResponse) : null,
            actionPreviews,
            actionResults,
        };
    }

    replaceResponse(task) {
        this.ai.applyTaskToSession(task);
    }

    actionPreviewsFor(task) {
        return task?.metadata?.action_previews ?? [];
    }

    normalizedActionPreviewsFor(task) {
        return this.actionPreviewsFor(task).map((preview) => {
            const result = this.actionResultFor(task, preview);
            const error = this.actionErrorFor(task, preview);

            return {
                ...preview,
                result,
                error,
                isDisabled: preview.ready !== true || Boolean(result) || Boolean(error) || task?.status === 'cancelled',
            };
        });
    }

    actionResultsFor(task) {
        return task?.metadata?.action_results ?? [];
    }

    actionErrorsFor(task) {
        return task?.metadata?.action_errors ?? [];
    }

    actionResultFor(task, preview) {
        return this.actionResultsFor(task).find((result) => result.action === preview.key || result.action === preview.action);
    }

    actionErrorFor(task, preview) {
        return this.actionErrorsFor(task).find((error) => error.action === preview.key || error.action === preview.action) ?? this.actionErrorsFor(task)[0];
    }

    @action focusPromptInput(inputEl) {
        if (inputEl && typeof inputEl.focus === 'function') {
            inputEl.focus();
            this.resizePromptTextarea(inputEl);
        }
    }

    @action handlePromptKeydown(event) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            this.submit();
        }
    }

    @action resizePromptTextarea(eventOrElement) {
        const textarea = eventOrElement?.target ?? eventOrElement;
        if (!textarea) {
            return;
        }

        textarea.style.height = 'auto';
        const nextHeight = Math.min(textarea.scrollHeight, 128);
        textarea.style.height = `${nextHeight}px`;
        textarea.style.overflowY = textarea.scrollHeight > 128 ? 'auto' : 'hidden';
    }

    @action async submit() {
        const prompt = this.prompt.trim();
        if (!prompt) {
            return;
        }

        this.shouldAutoScroll = true;
        const attachments = this.attachments;
        this.prompt = '';
        this.showHistory = false;
        this.scheduleResponseScroll({ force: true });
        await this.ai.submit.perform(prompt, attachments);
        this.attachments = [];
        this.scheduleResponseScroll();
    }

    @action close() {
        this.showHistory = false;
        this.aiEvents.closePrompt();
    }

    @action stopPropagation(event) {
        event.stopPropagation();
    }

    @action toggleHistory() {
        this.showHistory = !this.showHistory;
        if (this.showHistory) {
            this.ai.loadSessions.perform();
        }
    }

    @action async selectSession(session) {
        await this.ai.loadSession.perform(session);
        this.showHistory = false;
        this.scheduleResponseScroll({ force: true });
    }

    @action handleHistoryItemKeydown(session, event) {
        if (event.key !== 'Enter' && event.key !== ' ') {
            return;
        }

        event.preventDefault();
        this.selectSession(session);
    }

    @action async deleteSession(session, event) {
        event?.stopPropagation();

        if (!session || !window.confirm('Delete this AI chat?')) {
            return;
        }

        await this.ai.deleteSession.perform(session);
    }

    @action async startSession() {
        this.showHistory = false;
        await this.ai.startSession.perform();
        this.scheduleResponseScroll({ force: true });
    }

    @action async applyAction(task, preview, input = {}) {
        const updatedTask = await this.ai.applyTask.perform(task, preview.key ?? preview.action, input);
        this.replaceResponse(updatedTask);
        this.scheduleResponseScroll();
    }

    @action async refreshAction(task, preview, input = {}) {
        const updatedTask = await this.ai.refreshTaskPreview.perform(task, preview.key ?? preview.action, input);
        this.replaceResponse(updatedTask);
        this.scheduleResponseScroll();

        return this.normalizedActionPreviewsFor(updatedTask).find((updatedPreview) => updatedPreview.key === preview.key || updatedPreview.action === preview.action);
    }

    @action async cancelAction(task) {
        const updatedTask = await this.ai.cancelTask.perform(task);
        this.replaceResponse(updatedTask);
        this.scheduleResponseScroll();
    }

    @action registerResponseStack(element) {
        this.responseStackElement = element;
        this.isNearResponseBottom = true;
        this.showScrollToLatest = false;
        this.scheduleResponseScroll({ force: true });
    }

    @action unregisterResponseStack() {
        if (this.responseScrollFrame) {
            cancelAnimationFrame(this.responseScrollFrame);
            this.responseScrollFrame = null;
        }

        this.responseStackElement = null;
        this.isNearResponseBottom = true;
        this.showScrollToLatest = false;
        this.shouldAutoScroll = true;
    }

    @action handleResponseStackScroll() {
        const element = this.responseStackElement;
        if (!element) {
            return;
        }

        this.isNearResponseBottom = this.isResponseStackNearBottom(element);
        this.shouldAutoScroll = this.isNearResponseBottom;
        this.showScrollToLatest = !this.isNearResponseBottom && element.scrollHeight > element.clientHeight;
    }

    @action syncResponseScroll() {
        this.scheduleResponseScroll();
    }

    @action scrollToLatest(event) {
        event?.stopPropagation();
        this.shouldAutoScroll = true;
        this.scheduleResponseScroll({ force: true });
    }

    scheduleResponseScroll({ force = false } = {}) {
        if (this.responseScrollFrame) {
            cancelAnimationFrame(this.responseScrollFrame);
        }

        this.responseScrollFrame = requestAnimationFrame(() => {
            this.responseScrollFrame = null;

            const element = this.responseStackElement;
            if (!element) {
                return;
            }

            const shouldScroll = force || this.shouldAutoScroll || this.isResponseStackNearBottom(element);
            if (shouldScroll) {
                element.scrollTo({
                    top: element.scrollHeight,
                    behavior: force ? 'smooth' : 'auto',
                });
            }

            this.isNearResponseBottom = this.isResponseStackNearBottom(element);
            this.shouldAutoScroll = this.isNearResponseBottom;
            this.showScrollToLatest = !this.isNearResponseBottom && element.scrollHeight > element.clientHeight;
        });
    }

    isResponseStackNearBottom(element) {
        return element.scrollHeight - element.scrollTop - element.clientHeight <= 48;
    }

    @action removeAttachment(attachment) {
        this.attachments = this.attachments.filter((item) => item !== attachment);
    }

    @task({ enqueue: true, maxConcurrency: 3 }) *uploadAttachment(file) {
        if (!file || !['queued', 'failed', 'timed_out', 'aborted'].includes(file.state)) {
            return null;
        }

        this.uploadQueue = [...this.uploadQueue, file];

        try {
            const uploadedFile = yield this.fetch.uploadFile.perform(file, {
                path: `uploads/${this.currentUser.companyId}/ai/attachments`,
                type: 'ai_attachment',
            });

            if (uploadedFile) {
                this.attachments = [...this.attachments, this.normalizeAttachment(uploadedFile)];
            }

            return uploadedFile;
        } catch (error) {
            this.notifications.serverError(error, 'Unable to upload attachment.');

            return null;
        } finally {
            this.uploadQueue = this.uploadQueue.filter((queuedFile) => queuedFile !== file);
        }
    }

    normalizeAttachment(file) {
        return {
            id: file.id,
            uuid: file.uuid,
            public_id: file.public_id,
            name: file.original_filename ?? file.name ?? 'Attachment',
            content_type: file.content_type,
            file_size: file.file_size,
            displaySize: this.formatFileSize(file.file_size),
        };
    }

    formatFileSize(size) {
        const bytes = Number(size);
        if (!Number.isFinite(bytes) || bytes <= 0) {
            return null;
        }

        if (bytes < 1024) {
            return `${bytes} B`;
        }

        if (bytes < 1024 * 1024) {
            return `${Math.round(bytes / 102.4) / 10} KB`;
        }

        return `${Math.round(bytes / 1024 / 102.4) / 10} MB`;
    }
}
