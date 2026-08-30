package com.phpmystic.liqx.navigation;

import com.intellij.codeInsight.navigation.actions.GotoDeclarationHandler;
import com.intellij.openapi.editor.Editor;
import com.intellij.openapi.project.Project;
import com.intellij.openapi.vfs.VirtualFile;
import com.intellij.openapi.fileEditor.OpenFileDescriptor;
import com.intellij.openapi.util.TextRange;
import com.intellij.pom.Navigatable;
import com.intellij.psi.PsiElement;
import com.intellij.psi.PsiFile;
import com.intellij.psi.PsiManager;
import com.intellij.psi.impl.FakePsiElement;
import com.intellij.psi.search.FileTypeIndex;
import com.intellij.psi.search.GlobalSearchScope;
import com.phpmystic.liqx.LiqxFileType;
import org.jetbrains.annotations.NotNull;
import org.jetbrains.annotations.Nullable;

import java.util.ArrayList;
import java.util.Collection;
import java.util.List;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

public final class LiqxGotoDeclarationHandler implements GotoDeclarationHandler {

    private static final Pattern LOCAL_NAMED_TEMPLATE = Pattern.compile("<template\\s+[^>]*name\\s*=\\s*[\"']([^\"']+)[\"']", Pattern.CASE_INSENSITIVE);

    @Override
    public PsiElement @Nullable [] getGotoDeclarationTargets(@Nullable PsiElement sourceElement, int offset, Editor editor) {
        if (sourceElement == null) {
            return null;
        }

        PsiFile file = sourceElement.getContainingFile();
        if (file == null || file.getVirtualFile() == null) {
            return null;
        }

        String ext = file.getVirtualFile().getExtension();
        if (ext == null || !ext.equalsIgnoreCase("liqx")) {
            return null;
        }

        // Get the word under cursor
        CharSequence chars = editor.getDocument().getCharsSequence();
        String identifier = getIdentifierAtOffset(chars, offset);
        if (identifier == null || identifier.isBlank()) {
            return null;
        }

        // Don't navigate on built-in HTML/control tags
        if (isControlOrHtmlTag(identifier)) {
            return null;
        }

        Project project = sourceElement.getProject();
        List<PsiElement> targets = new ArrayList<>();

        // 1. Check same-file named templates (<template name="Identifier">)
        PsiElement localTemplate = findLocalNamedTemplate(file, identifier);
        if (localTemplate != null) {
            targets.add(localTemplate);
        }

        // 2. Search project files for snippets, blocks, or sections matching PascalCase or kebab-case
        String kebab = pascalToKebab(identifier);
        Collection<VirtualFile> liqxFiles = FileTypeIndex.getFiles(LiqxFileType.INSTANCE, GlobalSearchScope.projectScope(project));

        for (VirtualFile vf : liqxFiles) {
            String nameWithoutExt = vf.getNameWithoutExtension();
            if (nameWithoutExt.equalsIgnoreCase(kebab) || nameWithoutExt.equalsIgnoreCase(identifier)) {
                PsiFile targetPsi = PsiManager.getInstance(project).findFile(vf);
                if (targetPsi != null) {
                    targets.add(targetPsi);
                }
            }
        }

        return targets.isEmpty() ? null : targets.toArray(new PsiElement[0]);
    }

    private static @Nullable String getIdentifierAtOffset(CharSequence chars, int offset) {
        if (offset < 0 || offset > chars.length()) return null;

        int start = offset;
        while (start > 0 && isIdentChar(chars.charAt(start - 1))) {
            start--;
        }

        int end = offset;
        while (end < chars.length() && isIdentChar(chars.charAt(end))) {
            end++;
        }

        if (start >= end) return null;
        return chars.subSequence(start, end).toString();
    }

    private static boolean isIdentChar(char c) {
        return Character.isLetterOrDigit(c) || c == '_' || c == '-';
    }

    private static boolean isControlOrHtmlTag(String name) {
        String lower = name.toLowerCase();
        return lower.equals("if") || lower.equals("else") || lower.equals("elseif") ||
               lower.equals("show") || lower.equals("switch") || lower.equals("match") ||
               lower.equals("fallback") || lower.equals("template") || lower.equals("style") ||
               lower.equals("script") || lower.equals("schema") || lower.equals("div") ||
               lower.equals("span") || lower.equals("p") || lower.equals("a") ||
               lower.equals("button") || lower.equals("input") || lower.equals("form") ||
               lower.equals("h1") || lower.equals("h2") || lower.equals("h3") ||
               lower.equals("h4") || lower.equals("h5") || lower.equals("h6") ||
               lower.equals("ul") || lower.equals("ol") || lower.equals("li") ||
               lower.equals("img") || lower.equals("svg") || lower.equals("path") ||
               lower.equals("label") || lower.equals("select") || lower.equals("option") ||
               lower.equals("textarea") || lower.equals("nav") || lower.equals("header") ||
               lower.equals("footer") || lower.equals("section") || lower.equals("article");
    }

    private static @Nullable PsiElement findLocalNamedTemplate(PsiFile file, String name) {
        String text = file.getText();
        Matcher matcher = LOCAL_NAMED_TEMPLATE.matcher(text);
        while (matcher.find()) {
            String tName = matcher.group(1);
            if (tName.equalsIgnoreCase(name)) {
                int targetOffset = matcher.start(1);
                return new LocalTemplateElement(file, name, targetOffset);
            }
        }
        return null;
    }

    private static String pascalToKebab(String str) {
        return str.replaceAll("([a-z0-9])([A-Z])", "$1-$2").toLowerCase();
    }

    public static final class LocalTemplateElement extends FakePsiElement implements Navigatable {
        private final PsiFile file;
        private final String name;
        private final int offset;

        public LocalTemplateElement(PsiFile file, String name, int offset) {
            this.file = file;
            this.name = name;
            this.offset = offset;
        }

        @Override
        public PsiElement getParent() {
            return file;
        }

        @Override
        public PsiFile getContainingFile() {
            return file;
        }

        @Override
        public int getTextOffset() {
            return offset;
        }

        @Override
        public TextRange getTextRange() {
            return new TextRange(offset, offset + name.length());
        }

        @Override
        public String getName() {
            return name;
        }

        @Override
        public boolean isValid() {
            return file.isValid();
        }

        @Override
        public boolean canNavigate() {
            return true;
        }

        @Override
        public boolean canNavigateToSource() {
            return true;
        }

        @Override
        public void navigate(boolean requestFocus) {
            VirtualFile vf = file.getVirtualFile();
            if (vf != null) {
                new OpenFileDescriptor(getProject(), vf, offset).navigate(requestFocus);
            }
        }

        @NotNull
        @Override
        public Project getProject() {
            return file.getProject();
        }
    }
}
